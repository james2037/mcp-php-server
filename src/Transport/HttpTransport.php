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
    private bool $sseStreamPreferred = false; // Added for SSE preference

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

        // Reset SSE preference at the start of a new send cycle, get current preference
        $ssePreferenceForThisSend = $this->sseStreamPreferred;
        $this->sseStreamPreferred = false;

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

        if ($this->isNotificationOrResponseOnlyBatch($message)) {
            $this->response = $this->responseFactory->createResponse(202);
            $this->headersSent = true;
            return;
        }

        // If it's not a 202-batch, then determine if SSE or JSON
        if ($isSseRequestedByClient) {
            if ($isGetRequest) { // GET request explicitly asking for SSE
                $useSse = true;
            } else { // POST request and client accepts SSE
                if ($ssePreferenceForThisSend) { // Explicit server preference
                    $useSse = true;
                } elseif (!is_array($message)) {
                    // Fallback: If it's a single message, assume it could be the start of an SSE stream.
                    // If it's an array (batch of responses), it will use JSON by default ($useSse remains false).
                    $useSse = true;
                }
                // else: $message is an array (batch) and no explicit SSE preference, $useSse remains false for JSON batch.
            }
        }
        // If $isSseRequestedByClient is false, $useSse also remains false (unless $ssePreferenceForThisSend was true, which is a bit contradictory but possible)
        // However, if client doesn't accept SSE, we should probably not force it.
        // Let's refine: SSE is only used if client accepts it AND (GET or (POST with preference or POST single message))
        if (!$isSseRequestedByClient && $useSse) { // Correcting: if client doesn't want SSE, don't force it.
            $useSse = false;
        }

        if ($useSse) {
            $this->startSseStreamHeaders();

            if ($this->originValidationFailed) { // If origin validation failed in startSseStreamHeaders
                return; // The response is already set to 403, headersSent=true. Exit send().
            }

            // Only proceed to send data if the stream is actually open
            // (which implies origin was OK and startSseStreamHeaders completed successfully to that point)
            if ($this->streamOpen) {
                $this->sendSseEventData($this->prepareSseData($message));
            } else {
                // This case implies SSE was intended ($useSse=true) but stream didn't open,
                // and it wasn't due to an origin validation failure that startSseStreamHeaders handled by returning.
                // The response might still be the initial one (e.g. 200 OK with SSE headers but no body yet if startSseStreamHeaders didn't set 403).
            }
        } else { // Not using SSE
            $this->prepareJsonResponse($message);
        }
    }

    private function isNotificationOrResponseOnlyBatch(JsonRpcMessage|array $messageOrMessages): bool
    {
        $messages = is_array($messageOrMessages) ? $messageOrMessages : [$messageOrMessages];

        if (empty($messages)) { // No messages, so not a batch to consider for 202
            return false;
        }

        foreach ($messages as $msg) {
            if ($msg instanceof JsonRpcMessage && $msg->isRequest()) {
                return false;
            }
            if (!$msg instanceof JsonRpcMessage) { // Should not happen with proper usage
                return false;
            }
        }
        return true;
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
            // Direct echo and header calls removed. The Server/Emitter will handle sending the 403 response.
            return;
        }

        // Ensure response is fresh for SSE headers if it was previously used (e.g. for a non-200 initial response)
        // However, the constructor always creates a new response, and normal flow would use that.
        // If $this->response was set to 403, we would have returned already.
        // So, we can assume $this->response is the one from the constructor or a 200 response.
        $this->response = $this->responseFactory->createResponse(200) // Start with a fresh 200 response for SSE
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no');

        if ($this->serverSessionId !== null) {
            $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
        }

        // Set the body to a new stream for progressive writing by sendSseEventData
        $this->response = $this->response->withBody($this->streamFactory->createStream(''));
        $this->headersSent = false; // Headers are prepared on $this->response, but not "sent" by the transport itself.
                                   // The Server/Emitter will send them when getResponse() is called.
                                   // For SSE, headersSent might mean the *initial* headers for the stream.
                                   // Let's consider headersSent true once the stream is initiated.
        $this->headersSent = true;
        $this->streamOpen = true;
    }

    private function prepareSseData(JsonRpcMessage|array $messages): string
    {
        $this->sseEventCounter++;
        $eventId = $this->serverSessionId ? $this->serverSessionId . '-' . $this->sseEventCounter : 'event-' . $this->sseEventCounter; // Example event ID

        $payload = is_array($messages) ? JsonRpcMessage::toJsonArray($messages) : $messages->toJson();

        // Ensure no literal newlines in payload that would break SSE format for 'data:' line.
        // JsonRpcMessage::toJson() should produce a single line of JSON.
        // If the JSON string itself contains literal newlines (e.g., from json_encode with JSON_PRETTY_PRINT),
        // each line of that pretty-printed JSON would need to be prefixed with "data: ".
        // However, our JsonRpcMessage::toJson() produces compact JSON. Internal newlines are escaped (e.g. \n).
        // Therefore, the payload is typically a single line and doesn't need this specific escaping.
        // $escapedPayload = str_replace("\n", "\ndata: ", $payload); // This was incorrect for compact JSON payloads

        return "id: " . $eventId . "\n" . "data: " . $payload . "\n\n";
    }

    private function sendSseEventData(string $sseFormattedData): void
    {
        if (!$this->streamOpen) {
            // This implies headers for SSE were not prepared or sent.
            // This is an internal error in logic.
            throw new TransportException("SSE Stream not open, cannot send event data.");
        }
        // Write to the response body stream instead of echoing
        $this->response->getBody()->write($sseFormattedData);
        // Flushing the stream might be necessary depending on the stream implementation and server setup.
        // For now, direct flushOutput call is removed here. SAPI may handle flushing.
    }

    private function flushOutput(): void
    {
        // if (function_exists('ob_flush')) { // Keep PHP's output buffer intact for tests
        //     ob_flush();
        // }
        flush(); // Flush system output buffer if possible/needed
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

    public function preferSseStream(bool $prefer = true): void
    {
        $this->sseStreamPreferred = $prefer;
    }
}
