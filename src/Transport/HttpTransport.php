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
    // All SSE, DELETE, Origin, complex ACK logic, and related session properties are removed.
    // Kept essential properties for basic POST JSON-RPC.
    private ?ResponseInterface $response = null;
    private bool $responsePrepared = false; // To track if send() has been called

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
        // Constructor is simplified.
        // Removed logic:
        // - Mcp-Session-Id and Last-Event-ID header reading
        // - Origin validation logic
        // Initialize a default response object.
        $this->response = $this->responseFactory->createResponse();
    }

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
 // Type hint for $arr
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
            $jsonErrorString = json_encode($errorPayload);
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($jsonErrorString));
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
            $jsonPayloadString = json_encode($errorPayload); // This should not fail.
            $this->response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($jsonPayloadString));
        } else {
            $this->response = $this->responseFactory->createResponse(200)
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
}
