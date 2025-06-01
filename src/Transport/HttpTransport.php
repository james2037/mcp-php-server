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
    private bool $clientRequestWasAckOnly = false;
    private bool $isDeleteRequest = false;

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

        $method = $this->request->getMethod();

        if ($method === 'DELETE') {
            $this->isDeleteRequest = true;
            // No body processing for DELETE, signal to Server via isDeleteRequest() and null messages.
            return null;
        }

        if ($method !== 'POST') {
            // GET requests are for establishing SSE from client or resumability.
            // They don't carry MCP messages *to* the server in their body for processing by receive().
            // Other methods (PUT, PATCH, etc.) are not supported for message receiving in MCP.
            return null;
        }

        // From here, we are processing a POST request.
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
            $messages = [];

            if (is_array($decodedInput) && (empty($decodedInput) || array_keys($decodedInput) === range(0, count($decodedInput) - 1))) {
                if (empty($decodedInput)) {
                    // Still an empty batch, but let's consider if this should be an ack-only case.
                    // An empty batch itself doesn't fit "solely of notifications or responses".
                    // For now, it's not ack-only.
                    return []; // Empty batch
                }
                // This is a batch, parse it into JsonRpcMessage objects
                $messages = JsonRpcMessage::fromJsonArray($body);
            } elseif (is_object($decodedInput) || (is_array($decodedInput) && !empty($decodedInput))) {
                // This is a single message
                $messages = [JsonRpcMessage::fromJson($body)];
            } else {
                throw new TransportException('Invalid JSON-RPC message structure in POST body.', JsonRpcMessage::PARSE_ERROR);
            }

            // Now, check if the decoded input messages consist solely of notifications or responses.
            // A notification has no 'id' key. A response has an 'id' key.
            // A request has an 'id' key and a 'method' key.
            // The key is that the client is sending *us* notifications or responses.
            if (!empty($messages)) {
                $allNotificationsOrResponses = true;
                foreach ($decodedInput as $rawMessage) {
                    if (!is_array($rawMessage)) { // Should be an array (decoded from JSON object)
                        $allNotificationsOrResponses = false;
                        break;
                    }
                    $isNotification = !array_key_exists('id', $rawMessage);
                    $isResponse = array_key_exists('id', $rawMessage) && (array_key_exists('result', $rawMessage) || array_key_exists('error', $rawMessage)) && !array_key_exists('method', $rawMessage);
                    // A valid request would have 'method' and 'id' (unless it's a notification)
                    // A client might send a "request" that is actually a response object.
                    // The crucial part for "ack only" is that there's no "method" field for the server to act upon.
                    $isRequestToServer = array_key_exists('method', $rawMessage);

                    if ($isRequestToServer) {
                        $allNotificationsOrResponses = false;
                        break;
                    }
                    // If it's not a request, it's implicitly a notification (no id) or a response-like structure (has id, result/error)
                }
                if ($allNotificationsOrResponses) {
                    $this->clientRequestWasAckOnly = true;
                }
            }
            return $messages;

        } catch (\JsonException $e) {
            throw new TransportException('JSON Parse Error: ' . $e->getMessage(), JsonRpcMessage::PARSE_ERROR, $e);
        } catch (\Exception $e) { // Catch errors from JsonRpcMessage parsing
            throw new TransportException('Error parsing JSON-RPC message: ' . $e->getMessage(), JsonRpcMessage::INVALID_REQUEST, $e);
        }
    }

    public function send(JsonRpcMessage|array|null $message): void // Allow null for 202 case
    {
        if ($this->headersSent && !$this->streamOpen) {
            throw new TransportException("Cannot send new HTTP response; headers already sent for a non-SSE response.");
        }

        // MCP 2025-03-26: Pure Notification/Response Batch for POST requests
        // If client input was purely notifications or responses, and server isn't sending a payload, return 202.
        if ($this->request->getMethod() === 'POST' && $this->clientRequestWasAckOnly && ($message === null || (is_array($message) && empty($message)))) {
            $this->response = $this->responseFactory->createResponse(202);
            // Add Mcp-Session-Id if available, even for 202
            if ($this->serverSessionId !== null) {
                $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
            }
            $this->headersSent = true;
            $this->streamOpen = false; // Ensure stream is not considered open
            return;
        }
        // If message is null/empty at this point but it wasn't an ack-only scenario,
        // it might be an error or an intentional empty response that should be JSON.
        // For example, a server might process a request and explicitly decide to send an empty JSON array `[]` or `null` within a JSON response.
        // If $message is truly null (not just empty array), we might default to empty JSON if not SSE.
        // However, the JsonRpcMessage structure usually means $message won't be null if it's from server logic.
        // If it IS null, and we didn't hit the 202 case, it's likely an issue or needs specific handling for what that means.
        // For now, let's assume if $message is null here, it implies no content to send, which is different from an empty JSON array.
        // JsonRpcMessage::toJsonArray([]) would produce "[]". JsonRpcMessage->toJson() for a null-result response is also valid JSON.
        // Let's treat $message === null as "no response data", which might mean an error if not handled by 202.
        // For safety, if $message is null and we are not doing SSE, we should probably send `null` as JSON.
        // If $message is null and we intend SSE, that's an issue as SSE expects data.

        // Determine intent for the current message based on request headers and server preferences
        $acceptHeader = $this->request->getHeaderLine('Accept');
        $clientAcceptsJson = stripos($acceptHeader, 'application/json') !== false;
        $clientAcceptsSse = stripos($acceptHeader, 'text/event-stream') !== false;
        $isGetRequest = $this->request->getMethod() === 'GET';
        $ssePreferenceForThisSend = $this->sseStreamPreferred;
        $this->sseStreamPreferred = false; // Reset for the next independent send() call

        $useSseForCurrentCall = false;

        if ($isGetRequest) {
            if ($clientAcceptsSse) {
                // Handle GET for SSE Resumability or New SSE stream
                $hasLastEventId = $this->getLastEventId() !== null;
                if ($hasLastEventId || $ssePreferenceForThisSend) {
                    // Resumption attempt or server explicitly wants to send SSE on this GET
                    $useSseForCurrentCall = true;
                } else {
                    // Not a resumption and server does not want to open a new unsolicited SSE stream.
                    // Per MCP 2025-03-26 / shared hosting: return 405 Method Not Allowed.
                    $this->response = $this->responseFactory->createResponse(405)
                        ->withBody($this->streamFactory->createStream('')); // Empty body
                    // Add Mcp-Session-Id if available
                    if ($this->serverSessionId !== null) {
                        $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
                    }
                    $this->headersSent = true;
                    $this->streamOpen = false;
                    return;
                }
            } else {
                // Client sent GET but doesn't accept text/event-stream.
                // This is not a valid SSE request. Server should probably respond with 406 Not Acceptable,
                // or let it fall through to a non-SSE response if possible (though GET implies SSE).
                // For now, this will likely lead to a non-SSE response or error further down if $message is null.
                // If $message is not null, it will try to send JSON, which is unusual for GET.
                // A specific 406 response here might be more appropriate.
                // However, current logic will make $useSseForCurrentCall = false;
                // If $message is null, this will result in JSON 'null', which is not ideal for GET.
                // Let's refine this: if GET and no Accept: text/event-stream, it's a bad request for MCP context.
                 $this->response = $this->responseFactory->createResponse(406) // Not Acceptable
                    ->withBody($this->streamFactory->createStream('Client must accept text/event-stream for GET requests.'));
                if ($this->serverSessionId !== null) {
                    $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
                }
                $this->headersSent = true;
                $this->streamOpen = false;
                return;
            }
        } elseif ($this->request->getMethod() === 'POST') {
            if ($clientAcceptsSse && $clientAcceptsJson) {
                // Client accepts both. Server decides.
                // Use sseStreamPreferred or if the message implies a stream (e.g. not an array, or server intends to stream)
                if ($ssePreferenceForThisSend) { // Explicit server preference
                    $useSseForCurrentCall = true;
                } elseif ($message !== null && !is_array($message)) { // Single message often implies it could be part of a stream
                    $useSseForCurrentCall = true; // Default to SSE if server sends a single item and client accepts both
                } else {
                    // It's a batch or server doesn't prefer SSE for this message. Default to JSON.
                    $useSseForCurrentCall = false;
                }
            } elseif ($clientAcceptsSse) {
                // Client *only* accepts SSE for this POST (or SSE is listed first and we prioritize it).
                $useSseForCurrentCall = true;
            } elseif ($clientAcceptsJson) {
                // Client *only* accepts JSON.
                $useSseForCurrentCall = false;
            } else {
                // Client accepts neither, or Accept header is missing/empty.
                // Default to JSON response. Or Server could send 406 Not Acceptable.
                // For now, HttpTransport will assume JSON if no clear SSE signal.
                $useSseForCurrentCall = false;
            }
        }

        // If $message is null and we are attempting SSE (and it's not just opening a stream for future events e.g. on GET)
        // SSE requires data for an event. If server logic provides null for a data event, it's an issue.
        // However, for GET resumability, $message could be null if no immediate events to replay, but stream should open.
        if ($message === null && $useSseForCurrentCall && $this->request->getMethod() === 'POST') {
            // For POST, if trying SSE and message is null, this is an issue. Switch to JSON 'null'.
            $useSseForCurrentCall = false;
        }
        // For GET with $useSseForCurrentCall = true and $message = null, it means open stream without initial data event.

        // If an SSE stream is currently active
        if ($this->streamOpen) {
            // If current call wants SSE and there's a message, send it as an event.
            if ($useSseForCurrentCall && $message !== null) {
                $this->sendSseEventData($this->prepareSseData($message));
            } elseif (!$useSseForCurrentCall) {
                // Current call is JSON, but stream was open. Close stream and send JSON.
                $this->streamOpen = false;
                if ($this->originValidationFailed) {
                     $this->response = $this->responseFactory->createResponse(403)
                        ->withHeader('Content-Type', 'text/plain')
                        ->withBody($this->streamFactory->createStream('Origin not allowed.'));
                    $this->headersSent = true;
                    return;
                }
                // If $message became null and we are here, it means we are closing an SSE stream
                // and the final response should be JSON `null`.
                $this->prepareJsonResponse($message); // handles null by creating JSON 'null'
            }
            return; // Processing for this send() call is complete
        }

        // No SSE stream was previously active.

        // Handle outgoing "pure notification" batches if they are NOT to be sent over SSE.
        // This is about the *server's* outgoing message.
        // The $clientRequestWasAckOnly (202 response) is for *client's incoming* message.
        // This original check might still be relevant if the server itself constructs a batch of only notifications
        // and wants to send it as a fire-and-forget JSON, not meriting a 202 (as 202 is for client input).
        // However, the MCP spec for 202 is specific to client *input*.
        // An outgoing batch of notifications from the server should just be sent as JSON if not SSE.
        // So, removing the $this->isOutgoingBatchPurelyNotifications($message) check for a 202.
        // The 202 logic is handled at the beginning now.

        // At this point, stream is not open, and it's not a 202 based on client input.
        // Decide whether to start a new SSE stream or send a single JSON response.
        if ($useSseForCurrentCall) {
            // This covers:
            // 1. GET for resumability ($message might be null or have replayed events)
            // 2. GET for new stream preferred by server ($message might be null or have initial events)
            // 3. POST where SSE is chosen ($message should not be null here due to check above)
            $this->startSseStreamHeaders(); // Sets $this->streamOpen = true, $this->headersSent = true for stream headers
            if ($this->response->getStatusCode() === 403) { // Origin validation failed
                $this->streamOpen = false;
                return;
            }
            if ($this->streamOpen) {
                if ($message !== null) { // Only send data if there is a message
                    $this->sendSseEventData($this->prepareSseData($message));
                }
                // For POST text/event-stream, MCP implies the stream is for this request's related messages then closes.
                if ($this->request->getMethod() === 'POST') {
                    $this->streamOpen = false;
                }
                // For GET, the stream remains open until explicitly closed or client disconnects.
            }
        } else { // Send a normal JSON response
            $this->streamOpen = false;
            if ($this->originValidationFailed) {
                $this->response = $this->responseFactory->createResponse(403)
                    ->withHeader('Content-Type', 'text/plain')
                    ->withBody($this->streamFactory->createStream('Origin not allowed.'));
            } else {
                $this->prepareJsonResponse($message); // Handles null message by creating JSON 'null'
            }
            $this->headersSent = true;
        }
    }

    // Renamed: This checks if the *server's outgoing message* is purely notifications.
    // This is NOT for the client input ack (202) case.
    private function isOutgoingBatchPurelyNotifications(JsonRpcMessage|array|null $messageOrMessages): bool
    {
        if ($messageOrMessages === null) {
            return false; // No message is not a batch of notifications.
        }
        $messages = is_array($messageOrMessages) ? $messageOrMessages : [$messageOrMessages];

        if (empty($messages)) {
            return false; // An empty array is not considered a batch of notifications here.
        }

        foreach ($messages as $msg) {
            // Check if it's a JsonRpcMessage and a notification (id is null).
            if (!$msg instanceof JsonRpcMessage || $msg->id !== null) {
                return false; // Not a notification.
            }
        }
        return true; // All messages are notifications.
    }

    private function prepareJsonResponse(JsonRpcMessage|array|null $message): void // Allow null
    {
        if ($this->serverSessionId !== null) {
            $this->response = $this->response->withHeader('Mcp-Session-Id', $this->serverSessionId);
        }

        $json = 'null'; // Default for null message
        if ($message instanceof JsonRpcMessage) {
            $json = $message->toJson();
        } elseif (is_array($message)) {
            // Handles empty array to "[]"
            $json = JsonRpcMessage::toJsonArray($message);
        }
        // If $message was already null, $json remains 'null'.

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
        try {
            $this->response = $this->response->withBody($this->streamFactory->createStream(''));
        } catch (\Throwable $e) {
            $this->log("Error creating stream for SSE: " . $e->getMessage());
            // If stream creation fails, we can't proceed with SSE.
            // This is a critical failure for starting an SSE stream.
            // We should ensure streamOpen is false and headersSent reflects that we haven't fully set up.
            $this->headersSent = true; // Headers might have been partially set on $this->response
            $this->streamOpen = false;
            // Re-throw as a transport exception, Server should handle this by sending an error response.
            throw new TransportException("Failed to create stream for SSE: " . $e->getMessage(), 0, $e, true); // isCritical = true
        }

        $this->headersSent = true; // Initial headers for the stream are now considered "sent" to the Response object.
                                   // The actual transmission will be handled by a PSR-7 emitter.
        $this->streamOpen = true;
        $this->log("SSE stream started. Client Session ID: " . ($this->clientSessionId ?: 'N/A') . ", Server Session ID: " . ($this->serverSessionId ?: 'N/A'));
    }

    private function prepareSseData(JsonRpcMessage|array $messages): string
    {
        $this->sseEventCounter++;
        $baseId = $this->serverSessionId ?: ($this->clientSessionId ?: 'event');
        $eventId = $baseId . '-' . $this->sseEventCounter;

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
            $this->log("Error: Attempted to send SSE event, but stream is not open.");
            // This implies headers for SSE were not prepared or sent, or stream was prematurely closed.
            // This is an internal error in logic.
            throw new TransportException("SSE Stream not open, cannot send event data.");
        }

        try {
            $body = $this->response->getBody();
            if (!$body->isWritable()) {
                $this->streamOpen = false; // Mark stream as unusable
                $this->log("Error: SSE stream body is not writable.");
                throw new TransportException("SSE stream body is not writable.", 0, null, false); // isCritical = false, as Server might handle this
            }

            $body->write($sseFormattedData);

            // Explicitly flush output buffers to ensure SSE event is sent immediately.
            // This is crucial in PHP environments where output buffering might be active by default (e.g., php-fpm, web servers).
            // ob_flush() flushes the PHP output buffer, if active.
            // flush() flushes the system output buffer (e.g., web server's buffer).
            // These should be called in this order.
            if (ob_get_level() > 0) { // Check if output buffering is active
                ob_flush();
            }
            flush();

        } catch (\Throwable $e) {
            // Catch any throwable (Exception, Error) during write or flush.
            $this->streamOpen = false; // Mark stream as unusable after an error.
            $this->log("Error during SSE event send: " . $e->getMessage());
            throw new TransportException("Error sending SSE event data: " . $e->getMessage(), 0, $e, false); // isCritical = false
        }
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
        // If headersSent is true AND it wasn't an SSE stream (checked by the if above), the request/response cycle is done.
        if ($this->headersSent) {
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

    public function isStreamOpen(): bool
    {
        return $this->streamOpen;
    }

    public function isDeleteRequest(): bool
    {
        return $this->isDeleteRequest;
    }

    public function prepareResponseForDelete(int $statusCode): void
    {
        if (!$this->isDeleteRequest) {
            // This method should only be called if it's a DELETE request.
            // However, Server logic should ensure this.
            // For robustness, one might throw an exception or log a warning.
            // For now, we'll trust Server to call it correctly.
        }

        $this->response = $this->responseFactory->createResponse($statusCode);

        // For 204 No Content, ensure no body and Content-Length is 0 or absent.
        // PSR-7 response factory or emitter should handle this for 204.
        // Explicitly setting an empty body is good practice.
        $this->response = $this->response->withBody($this->streamFactory->createStream(''));

        if ($statusCode === 204) {
            // Ensure Content-Type is not sent for 204 responses.
            // Some PSR-7 ResponseInterface implementations might add a default Content-Type.
            $this->response = $this->response->withoutHeader('Content-Type');
        }

        // Optionally, include Mcp-Session-Id from the request if it was present.
        // This might be useful if the server wants to confirm which session ID was acted upon,
        // or if client needs it for any reason (though less common for DELETE).
        // if ($this->clientSessionId !== null) {
        //     $this->response = $this->response->withHeader('Mcp-Session-Id', $this->clientSessionId);
        // }

        $this->headersSent = true;
        $this->streamOpen = false;
    }
}
