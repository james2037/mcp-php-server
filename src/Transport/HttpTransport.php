<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Laminas\Diactoros\ResponseFactory;      // For default factory
use Laminas\Diactoros\StreamFactory;       // For default factory
use Laminas\Diactoros\ServerRequestFactory;

/**
 * Implements the TransportInterface for HTTP communication.
 *
 * This transport handles JSON-RPC messages over HTTP POST requests.
 * It uses PSR-7 interfaces (ServerRequestInterface, ResponseInterface,
 * ResponseFactoryInterface, StreamFactoryInterface) for request and response handling,
 * making it compatible with various PSR-7 implementations and middleware.
 *
 * Note: This class has been simplified. All SSE, specific GET/DELETE handling,
 * Origin checks, complex ACK logic, and related session properties have been removed.
 * It primarily focuses on basic JSON-RPC via POST.
 */
class HttpTransport extends AbstractTransport
{
    // Kept essential properties for basic POST JSON-RPC.
    /** @var ResponseInterface|null The PSR-7 response object being prepared. */
    private ?ResponseInterface $response = null;
    /** @var bool Flag to track if the send() method has been called and the response is ready. */
    private bool $responsePrepared = false; // To track if send() has been called
    /** @var bool Flag to indicate if the last received request body appeared to be a batch. */
    private bool $lastRequestAppearedBatch = false;

    /** @var ServerRequestInterface The current PSR-7 server request. */
    protected ServerRequestInterface $request;
    /** @var ResponseFactoryInterface Factory for creating PSR-7 response objects. */
    private ResponseFactoryInterface $responseFactory;
    /** @var StreamFactoryInterface Factory for creating PSR-7 stream objects. */
    private StreamFactoryInterface $streamFactory;

    /**
     * Constructs an HttpTransport instance.
     *
     * @param ResponseFactoryInterface|null $responseFactory Optional PSR-7 response factory.
     *                                                       Defaults to Laminas Diactoros ResponseFactory.
     * @param StreamFactoryInterface|null $streamFactory Optional PSR-7 stream factory.
     *                                                     Defaults to Laminas Diactoros StreamFactory.
     * @param ServerRequestInterface|null $request Optional PSR-7 server request.
     *                                             Defaults to a request created from globals.
     */
    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ServerRequestInterface $request = null
    ) {
        $this->responseFactory = $responseFactory ?? new ResponseFactory();
        $this->streamFactory = $streamFactory ?? new StreamFactory();
        $this->request = $request ?? ServerRequestFactory::fromGlobals();

        // Initialize a default response object.
        $this->response = $this->responseFactory->createResponse();
    }

    /**
     * Receives and decodes the JSON-RPC request payload from the HTTP request.
     *
     * Expects a POST request with 'application/json' Content-Type.
     * Validates the HTTP request and uses `parseMessages` to process the body.
     *
     * @return JsonRpcMessage[]|null|false An array of `JsonRpcMessage` objects if successful,
     *                                     `null` if the request body is effectively empty (after trim),
     *                                     or `false` if critical HTTP validation (method, Content-Type) fails.
     * @throws TransportException If `parseMessages()` encounters an error (e.g., malformed JSON,
     *                            invalid JSON-RPC structure, message too large), this exception will propagate.
     */
    public function receive(): array|null|false
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->log('HttpTransport::receive: Invalid request method: ' . $this->request->getMethod());
            // Optionally set a specific error response here if desired,
            // but returning false signals transport-level unsuitability for this request.
            // The Server should ideally handle this by not attempting to process further with this transport.
            // If a response is desired, it should be a 405 Method Not Allowed.
            // For now, stick to `false` as per subtask interpretation.
            return false;
        }

        $contentType = $this->request->getHeaderLine('Content-Type');
        if (stripos($contentType, 'application/json') === false && stripos($contentType, 'application/json-rpc') === false) {
            $this->log('HttpTransport::receive: Invalid Content-Type: ' . $contentType);
            // Similar to above, returning false. Server might produce a 415 Unsupported Media Type.
            return false;
        }

        $body = (string) $this->request->getBody();
        // $this->request->getBody()->rewind(); // Ensure stream can be re-read if necessary

        // Determine if the raw body string looks like a batch before parsing
        $trimmedBody = trim($body);
        if (str_starts_with($trimmedBody, '[') && str_ends_with($trimmedBody, ']')) {
            $this->lastRequestAppearedBatch = true;
        } else {
            $this->lastRequestAppearedBatch = false;
        }

        // `parseMessages` handles empty body (after trim) by returning null.
        // It also handles JSON parsing errors and structure validation by throwing TransportException.
        try {
            return $this->parseMessages($body); // $body is untrimmed, parseMessages will trim
        } catch (TransportException $e) {
            // Log the exception and let it propagate.
            $this->log("TransportException in HttpTransport::receive while calling parseMessages: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
            throw $e;
        }
    }

    /**
     * Checks if the last request received by this transport appeared to be a batch request
     * based on its raw structure (e.g., starting with '[' and ending with ']').
     *
     * Note: This is a heuristic based on the raw body. `parseMessages` might still return
     * a single message if the batch array was empty or contained only one valid message after parsing.
     *
     * @return bool True if the last request body looked like a batch, false otherwise.
     */
    public function lastRequestAppearedAsBatch(): bool
    {
        return $this->lastRequestAppearedBatch;
    }

    /**
     * Prepares the PSR-7 response with the JSON-RPC payload.
     *
     * This method takes a single JsonRpcMessage or an array of JsonRpcMessages (for batch),
     * encodes it to a JSON string, and sets it as the body of the PSR-7 response object.
     * The response is typically a 200 OK, or a 500 Internal Server Error if encoding/serialization fails.
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $messageOrPayload The message or array of messages to send.
     *        If JsonRpcMessage, it will be serialized using its `toJson()` method.
     *        If array (of JsonRpcMessage), it is serialized using `JsonRpcMessage::toJsonArray()`.
     */
    public function send(JsonRpcMessage|array $messageOrPayload): void
    {
        // Adhering to TransportInterface: send(JsonRpcMessage|array $message)
        // The prompt suggested send(array $messages) but this would break the interface.
        // We will internally assume if $messageOrPayload is an array, it's JsonRpcMessage[].

        $jsonPayloadString = '';

        try {
            if ($messageOrPayload instanceof JsonRpcMessage) {
                $jsonPayloadString = $messageOrPayload->toJson();
            } elseif (is_array($messageOrPayload)) {
                // Assumed to be JsonRpcMessage[] as per intent.
                // JsonRpcMessage::toJsonArray will validate this.
                // If $messageOrPayload is an empty array, toJsonArray([]) returns "[]".
                $jsonPayloadString = JsonRpcMessage::toJsonArray($messageOrPayload);
            } else {
                // This case should not be reached if called correctly according to type hint.
                // However, if it was, create a JSON-RPC error.
                $errorMsg = JsonRpcMessage::error(
                    JsonRpcMessage::INTERNAL_ERROR,
                    'Server error: Invalid message type for sending.',
                    null // No ID available
                );
                $jsonPayloadString = $errorMsg->toJson();
                $this->response = $this->responseFactory->createResponse(500)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($jsonPayloadString));
                $this->responsePrepared = true;
                $this->log('HttpTransport::send: Invalid message type provided.');
                return;
            }
        } catch (\Exception $e) {
            // Catch exceptions during toJson() or toJsonArray() (e.g., InvalidArgumentException from toJsonArray)
            $this->log('HttpTransport::send: Error during message serialization: ' . $e->getMessage());
            $errorMsg = JsonRpcMessage::error(
                JsonRpcMessage::INTERNAL_ERROR,
                'Server error: Failed to serialize JSON response.',
                null // ID is difficult to determine reliably here.
            );
            $jsonPayloadString = $errorMsg->toJson(); // This should be safe.
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($jsonPayloadString));
            $this->responsePrepared = true;
            return;
        }

        // At this point, $jsonPayloadString is successfully created.
        // Default HTTP status 200. Specific error statuses (e.g. 400, 500 for JSON-RPC errors)
        // are typically embedded in the JsonRpcMessage error object, not as HTTP status codes,
        // unless the error is at the HTTP transport level itself.
        $this->response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($jsonPayloadString));

        $this->responsePrepared = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isStreamOpen(): bool
    {
        return false; // Simplified transport does not support streaming.
    }

    /**
     * {@inheritdoc}
     */
    public function isClosed(): bool
    {
        // Considered "closed" or cycle complete once send() has prepared the response.
        return $this->responsePrepared;
    }

    /**
     * Returns the prepared PSR-7 Response.
     * This should be called by the server's main loop (or equivalent)
     * to get the response object that was prepared by the `send` method.
     */
    public function getResponse(): ResponseInterface
    {
        if ($this->response === null) {
            // This case should ideally not be reached if send() is always called before getResponse(),
            // or if the constructor initializes a default response.
            // Fallback if $this->response is somehow still null.
            $errorPayload = json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => JsonRpcMessage::INTERNAL_ERROR, 'message' => 'Response not prepared or available.'],
                'id' => null
            ]);
            // Ensure response is created here if it's null
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($errorPayload));
        }
        return $this->response;
    }
}
