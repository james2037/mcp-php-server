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
class HttpTransport implements TransportInterface
{
    // Kept essential properties for basic POST JSON-RPC.
    /** @var ResponseInterface|null The PSR-7 response object being prepared. */
    private ?ResponseInterface $response = null;
    /** @var bool Flag to track if the send() method has been called and the response is ready. */
    private bool $responsePrepared = false; // To track if send() has been called

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
     * Validates the JSON structure (must be a JSON object or an array of JSON objects).
     * This method does not parse into JsonRpcMessage objects itself but returns the
     * raw associative array(s) decoded from the JSON payload.
     *
     * @return array<int|string, mixed> Returns an associative array for a single JSON-RPC request,
     *               or a list of associative arrays for a batch request.
     * @throws TransportException If the request method is not POST, Content-Type is not JSON,
     *                            the body is empty, JSON is malformed, or the JSON structure
     *                            is not a valid JSON-RPC request object or batch.
     */
    public function receive(): array
    {
        if ($this->request->getMethod() !== 'POST') {
            throw new TransportException('Only POST requests are supported.', JsonRpcMessage::INVALID_REQUEST);
        }

        $contentType = $this->request->getHeaderLine('Content-Type');
        if (stripos($contentType, 'application/json') === false) {
            throw new TransportException('Content-Type must be application/json.', JsonRpcMessage::INVALID_REQUEST);
        }

        $body = (string) $this->request->getBody();
        if (empty($body)) {
            throw new TransportException('Request body cannot be empty for JSON-RPC POST.', JsonRpcMessage::INVALID_REQUEST);
        }

        try {
            $parsedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            // Basic validation for JSON-RPC structure (object or array of objects).
            // json_decode($body, true) turns JSON objects into associative arrays.
            $isAssocArray = function (array $arr): bool {
                // An empty array is not an associative array in JSON-RPC object context.
                // An associative array has string keys or non-sequential integer keys.
                // array_is_list() checks for sequential, 0-indexed integer keys.
                // So, if it's not a list, it's considered associative for this check.
                return !empty($arr) && !array_is_list($arr);
            };

            $isValidStructure = false;
            if (is_array($parsedBody)) {
                if (array_is_list($parsedBody)) { // Potential Batch
                    if (!empty($parsedBody)) { // Batch array cannot be empty itself
                        $allBatchItemsValid = true;
                        foreach ($parsedBody as $item) {
                            if (!is_array($item) || !$isAssocArray($item)) { // Each item must be a non-empty associative array
                                $allBatchItemsValid = false;
                                break;
                            }
                        }
                        if ($allBatchItemsValid) {
                            $isValidStructure = true;
                        }
                    }
                } elseif ($isAssocArray($parsedBody)) { // Single request (must be a non-empty associative array)
                    $isValidStructure = true;
                }
            }

            if (!$isValidStructure) {
                 throw new TransportException('Invalid JSON-RPC: Request must be a JSON object or an array of JSON objects.', JsonRpcMessage::INVALID_REQUEST);
            }

            // $this->clientRequestWasAckOnly logic removed.
            // The JsonRpcMessage parsing (fromJson, fromJsonArray) is removed from transport.
            // Transport returns the raw associative array(s).
            return $parsedBody;
        } catch (\JsonException $e) {
            throw new TransportException('JSON Parse Error: ' . $e->getMessage(), JsonRpcMessage::PARSE_ERROR, $e);
        }
        // Removed: catch (\Exception $e) for JsonRpcMessage parsing, as it's no longer done here.
    }

    /**
     * Prepares the PSR-7 response with the JSON-RPC payload.
     *
     * This method takes a single JsonRpcMessage, an array of JsonRpcMessages (for batch),
     * or a pre-formatted payload array, encodes it to a JSON string, and sets it
     * as the body of the PSR-7 response object. The response is typically a 200 OK,
     * or a 500 Internal Server Error if encoding fails.
     *
     * @param JsonRpcMessage|array<mixed>|null $messageOrPayload The message(s) or pre-formatted payload to send.
     *        If JsonRpcMessage, it will be serialized. If array, it's assumed to be a batch
     *        of JsonRpcMessage objects or a ready-to-encode payload. Null means no specific
     *        payload (though usually an error or empty response would be structured).
     */
    public function send(JsonRpcMessage|array|null $messageOrPayload): void
    {
        // SSE, GET/DELETE, Origin validation, and complex ack-only (202) logic is removed.
        // This method now only prepares a standard JSON-RPC response for POST requests.

        $payloadToEncode = null;

        if ($messageOrPayload instanceof JsonRpcMessage) {
            // If JsonRpcMessage implements JsonSerializable, json_encode will call jsonSerialize()
            $payloadToEncode = $messageOrPayload;
        } elseif (is_array($messageOrPayload)) {
            // Handles an array of JsonRpcMessage objects or a pre-formatted payload array.
            // If all elements are JsonRpcMessage, they will be serialized by json_encode.
            // If it's a plain array (e.g. batch response), it's used as is.
            $payloadToEncode = $messageOrPayload;
        } elseif ($messageOrPayload === null) {
            // If payload is null, an empty JSON response might be intended (e.g. "[]" for empty batch, or "" which becomes "null").
            // For JSON-RPC, a response to a request usually has 'result' or 'error'.
            // A notification expects no response. If this is for a request expecting a response,
            // it might result in a parsing error on the client if it's not valid JSON-RPC (e.g. just "null").
            // For now, allow encoding null, which results in JSON "null".
            $payloadToEncode = null;
        } else {
            // Should not be reached due to type hints. Internal error if it does.
            // Prepare a JSON-RPC error response.
            $errorPayload = [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => JsonRpcMessage::INTERNAL_ERROR,
                    'message' => 'Server error: Invalid message type for sending.'
                ],
                'id' => null
            ];
            $encodedErrorString = json_encode($errorPayload);
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encodedErrorString !== false ? $encodedErrorString : '{"jsonrpc":"2.0","error":{"code":-32000,"message":"Server JSON encoding error"}}'));
            $this->responsePrepared = true;
            return;
        }

        $jsonPayloadString = json_encode($payloadToEncode);

        if ($jsonPayloadString === false) {
            // JSON encoding failed. Prepare a standard JSON-RPC error.
            $errorPayload = [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => JsonRpcMessage::INTERNAL_ERROR,
                    'message' => 'Server error: Failed to encode JSON response: ' . json_last_error_msg()
                ],
                'id' => null // ID is difficult to determine reliably at this stage for a failed batch.
            ];
            $encodedErrorString = json_encode($errorPayload); // This should not fail.
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encodedErrorString !== false ? $encodedErrorString : '{"jsonrpc":"2.0","error":{"code":-32000,"message":"Server JSON encoding error"}}'));
        } else {
            // Original behavior: always return 200 OK if JSON encoding is successful
            $httpStatus = 200;
            // The check for ($payloadToEncode instanceof JsonRpcMessage && $payloadToEncode->error !== null)
            // was part of the detailed status code mapping. If we revert to always 200 (or 500 on encode failure),
            // this specific check isn't strictly needed here for status determination but can be kept for clarity
            // if we want to log errors, etc. For minimal change from "original" simple 200/500, it's not used to change status.

            $this->response = $this->responseFactory->createResponse($httpStatus)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($jsonPayloadString));
        }
        $this->responsePrepared = true;
        // The response is stored in $this->response and will be retrieved by getResponse().
        // No direct output or header manipulation here.
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

    /**
     * Indicates a preference for using Server-Sent Events (SSE) for streaming responses, if applicable.
     *
     * Transports that support SSE (like HttpTransport) can use this hint to switch
     * their response mode. Other transports can ignore this.
     *
     * @param bool $prefer True to prefer SSE, false otherwise.
     */
    public function preferSseStream(bool $prefer = true): void
    {
        // Default implementation does nothing, to be overridden by transports that support SSE.
        // In a real HttpTransport that supports SSE, this method would set a flag
        // to change response content-type and formatting in the send() method.
        // For this simplified version, it remains a no-op.
    }
}
