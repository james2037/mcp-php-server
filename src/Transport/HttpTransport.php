<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpTransport extends AbstractTransport
{
    private ServerRequestInterface $request;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    private bool $headersSent = false; // To track if the response headers (not SSE events) have been sent
    private bool $streamOpen = false;  // For active SSE stream
    private ?ResponseInterface $response = null; // To hold the response being built
    private ?string $clientSessionId = null;
    private ?string $serverSessionId = null;
    private ?string $lastEventId = null;
    private int $sseEventCounter = 0;
    private array $allowedOrigins;
    private bool $originValidationFailed = false;

    // TODO: Add SSL configuration options (less relevant if behind a reverse proxy)

    public function __construct(
        ServerRequestInterface $request,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $allowedOrigins = [] // new parameter
    ) {
        $this->request = $request;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->response = $this->responseFactory->createResponse();

        $this->clientSessionId = $this->request->getHeaderLine('Mcp-Session-Id') ?: null;
        $this->lastEventId = $this->request->getHeaderLine('Last-Event-ID') ?: null;
        $this->allowedOrigins = $allowedOrigins;

        $origin = $this->request->getHeaderLine('Origin');
        if (!empty($origin)) { // An Origin header is present
            $isAllowed = false;
            if (empty($this->allowedOrigins)) {
                // If no allowedOrigins are configured, by default, no cross-origin requests (those that send an Origin header) are permitted.
                $isAllowed = false;
            } else {
                foreach ($this->allowedOrigins as $allowed) {
                    if (strtolower($origin) === strtolower($allowed)) {
                        $isAllowed = true;
                        break;
                    }
                }
            }
            if (!$isAllowed) {
                $this->originValidationFailed = true;
                $this->log("Origin validation failed for: " . $origin);
            }
        }
        // If Origin header is not present, it's considered acceptable (e.g. same-origin, non-browser client)
    }

    public function receive(): ?array
    {
        if ($this->originValidationFailed) {
            // Error code -32001 (could be any server-defined error)
            throw new TransportException('Origin not allowed.', -32001);
        }
        if ($this->request->getMethod() !== 'POST') {
            // GET requests are for establishing SSE from client or resumability.
            // They don't carry MCP messages *to* the server in their body for processing by receive().
            return null;
        }

        $contentType = $this->request->getHeaderLine('Content-Type');
        if (stripos($contentType, 'application/json') === false) {
            // MCP requires JSON, though specific error handling might differ.
            // This could lead to a 415 Unsupported Media Type response.
            // For now, treat as no valid message received.
            // The Server class might generate an error if it receives null for a required message.
            // Or, we can throw a TransportException here to be caught by the Server.
            throw new TransportException('Invalid Content-Type. Must be application/json.', JsonRpcMessage::INVALID_REQUEST);
        }

        $body = (string) $this->request->getBody();
        if (empty($body)) {
            return null; // No body content
        }

        try {
            $decodedInput = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decodedInput) && (empty($decodedInput) || array_keys($decodedInput) === range(0, count($decodedInput) - 1))) {
                if (empty($decodedInput)) {
                    return []; // Empty batch
                }
                return JsonRpcMessage::fromJsonArray($body);
            } elseif (is_object($decodedInput) || (is_array($decodedInput) && !empty($decodedInput))) {
                $message = JsonRpcMessage::fromJson($body);
                return [$message];
            } else {
                throw new TransportException('Invalid JSON-RPC message structure in POST body.', JsonRpcMessage::PARSE_ERROR);
            }
        } catch (\JsonException $e) {
            throw new TransportException('JSON Parse Error: ' . $e->getMessage(), JsonRpcMessage::PARSE_ERROR, $e);
        } catch (\Exception $e) { // Catch errors from JsonRpcMessage parsing
            throw new TransportException('Error parsing JSON-RPC message: ' . $e->getMessage(), JsonRpcMessage::INVALID_REQUEST, $e);
        }
    }

    public function send(JsonRpcMessage|array $message): void
    {
        if ($this->headersSent && !$this->streamOpen) {
            throw new TransportException("Cannot send new HTTP response; headers already sent for a non-SSE response.");
        }

        // If it's an ongoing SSE stream, send event
        if ($this->streamOpen) {
            $this->sendSseEventData($this->prepareSseData($message));
            return;
        }

        // Determine if this should be an SSE response or a single JSON response.
        $acceptHeader = $this->request->getHeaderLine('Accept');
        $isSseRequestedByClient = stripos($acceptHeader, 'text/event-stream') !== false;
        $isGetRequest = $this->request->getMethod() === 'GET';

        // Server decides to use SSE if:
        // 1. It's a GET request explicitly asking for SSE (for server-initiated events).
        // 2. It's a POST request, client accepts SSE, AND the server intends to stream multiple messages
        //    (e.g., multiple responses for a batch, or subsequent updates/notifications).
        //    This "server intends to stream" is a crucial part. For now, we might simplify:
        //    If client accepts SSE on a POST, and the $message is an array (implying batch or multiple parts),
        //    or if a flag is set on the transport to prefer SSE.

        // Simplified decision for now:
        $useSse = false;
        if ($isGetRequest && $isSseRequestedByClient) {
            $useSse = true;
        } elseif ($this->request->getMethod() === 'POST' && $isSseRequestedByClient) {
            // If it's a batch response, or if we want to keep the connection open for more messages.
            // For now, let's say if $message is an array (batch response), use SSE if requested.
            // A more sophisticated check is needed for "server intends to stream".
            if (is_array($message) && count($message) > 1) { // Simple heuristic for batch
                 $useSse = true;
            }
            // Also, if the input was *only* notifications/responses, spec says 202 Accepted.
            if ($this->isNotificationOrResponseOnlyBatch($message)) {
                $this->response = $this->responseFactory->createResponse(202);
                $this->headersSent = true; // Mark as "final" response sent
                return; // No further output
            }
        }


        if ($useSse) {
            $this->startSseStreamHeaders();
            $this->sendSseEventData($this->prepareSseData($message));
        } else {
            $this->prepareJsonResponse($message);
        }
    }

    private function isNotificationOrResponseOnlyBatch(JsonRpcMessage|array $messages): bool
    {
        if (!is_array($messages) || empty($messages)) {
            return false;
        }
        foreach ($messages as $msg) {
            if ($msg instanceof JsonRpcMessage && $msg->isRequest()) {
                return false; // Found a request, so not "response/notification only"
            }
        }
        return true; // All messages are responses or notifications
    }

    private function prepareJsonResponse(JsonRpcMessage|array $message): void
    {
        if ($this->serverSessionId !== null) {
            $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
        }
        $json = is_array($message) ? JsonRpcMessage::toJsonArray($message) : $message->toJson();
        $this->response = $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($json));
        $this->headersSent = true; // Mark as "final" response prepared
    }

    private function startSseStreamHeaders(): void
    {
        if ($this->originValidationFailed) {
            // Prepare a 403 Forbidden style JSON-RPC error if possible,
            // or let the getResponse() reflect this.
            // This state should ideally prevent Server from even calling send() with actual data.
            $this->response = $this->responseFactory->createResponse(403)->withBody($this->streamFactory->createStream('Origin not allowed'));
            $this->headersSent = true; // Mark as "final" response prepared
            // Ensure the response object is updated for getResponse()
            if (!headers_sent()) {
                 // Send minimal headers for the 403 response
                http_response_code($this->response->getStatusCode());
                header('Content-Type: text/plain'); // Or application/json if sending a JSON error
                echo $this->response->getBody();
            }
            // We must not proceed to send SSE headers or open the stream further.
            // Throwing an exception here might be cleaner if Server::run can catch it before streamSseEvent.
            // For now, setting response and returning.
            return;
        }
        if ($this->serverSessionId !== null) {
            $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
        }
        $this->response = $this->response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no'); // For Nginx

        // SSE body will be written progressively. We need a way to signal this.
        // The actual sending of headers and initial body content happens when `getResponse` is called
        // and the PSR-7 emitter sends it.
        // For now, we are "preparing" the response.
        // The body of this initial response for SSE should be empty or just initial comments.
        // The actual stream writing needs a different mechanism than just setting $this->response.
        // This design needs refinement for PSR-7 stream emitting.

        // Option: `send()` returns the initial SSE response. A separate method `streamSseEvent()` echoes.
        // This means the Server's `run()` loop needs to change significantly for HttpTransport.

        // For this subtask, let's focus on making `send` prepare $this->response.
        // The actual *streaming* part will require more architectural thought.
        $this->response = $this->response->withBody($this->streamFactory->createStream('')); // Empty initial body for SSE
        $this->headersSent = true; // Initial headers are set
        $this->streamOpen = true;

        // To actually send headers and start stream for real-time echo:
        // This part breaks strict PSR-7 return, but is needed for classic PHP SSE.
        if (!headers_sent()) {
            http_response_code($this->response->getStatusCode());
            foreach ($this->response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
            // Echo initial SSE comment to open stream if desired by spec/client
            // echo ": stream open\n\n";
            // $this->flushOutput();
        }
    }

    private function prepareSseData(JsonRpcMessage|array $messages): string
    {
        $this->sseEventCounter++;
        $eventId = $this->serverSessionId ? $this->serverSessionId . '-' . $this->sseEventCounter : 'event-' . $this->sseEventCounter; // Example event ID

        $payload = is_array($messages) ? JsonRpcMessage::toJsonArray($messages) : $messages->toJson();

        // Ensure no literal newlines in payload that would break SSE format for 'data:' line.
        // JsonRpcMessage::toJson() should produce a single line of JSON.
        // If payload could have newlines intended for multi-line data fields:
        $escapedPayload = str_replace("\n", "\ndata: ", $payload);

        return "id: " . $eventId . "\n" . "data: " . $escapedPayload . "\n\n";
    }

    private function sendSseEventData(string $sseFormattedData): void
    {
        if (!$this->streamOpen) {
            // This implies headers for SSE were not prepared or sent.
            // This is an internal error in logic.
            throw new TransportException("SSE Stream not open, cannot send event data.");
        }
        // This is where we'd write to the actual output stream.
        // In a classic PHP setup, this is `echo`.
        echo $sseFormattedData;
        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    public function log(string $message): void
    {
        error_log("HttpTransport: " . $message);
    }

    public function isClosed(): bool
    {
        if ($this->streamOpen) {
            return connection_aborted() === 1;
        }
        // If headersSent is true AND it wasn't an SSE stream, the request/response cycle is done.
        if ($this->headersSent && !$this->streamOpen) {
            return true;
        }
        return false; // Default
    }

    /**
     * Returns the prepared PSR-7 Response.
     * This should be called by the server's main loop to get the response
     * and send it via a PSR-7 emitter.
     * Note: For SSE, this returns the *initial* response. Subsequent events
     * are echoed directly by sendSseEventData(). This is a hybrid approach.
     */
    public function getResponse(): ResponseInterface
    {
        if ($this->response === null) {
            // Should have been initialized in constructor or by send()
            // If receive() threw an exception that was caught by Server,
            // Server might then call send() with an error JsonRpcMessage.
            // So, ensure response is always creatable.
            $this->response = $this->responseFactory->createResponse(500);
        }
        return $this->response;
    }

    // TODO: Implement session management (Mcp-Session-Id using PSR-7 headers)
    // TODO: Implement resumability (Last-Event-ID using PSR-7 headers)
    // TODO: Implement Origin header validation (from PSR-7 request)

    public function setServerSessionId(string $sessionId): void
    {
        $this->serverSessionId = $sessionId;
    }

    public function getClientSessionId(): ?string
    {
        return $this->clientSessionId;
    }

    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }
}
