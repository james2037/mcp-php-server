<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

/**
 * Abstract base class for message transports.
 * Provides common functionality for message transports, including message
 * encoding/decoding, size validation, and basic logging. Subclasses must
 * implement the core `receive` and `send` methods as defined in TransportInterface.
 */
abstract class AbstractTransport implements TransportInterface
{
    /**
     * Maximum allowed message size in bytes (currently 10MB).
     * Used to prevent excessively large messages from being processed.
     */
    protected const MAX_MESSAGE_SIZE = 10 * 1024 * 1024;

    /**
     * Validates and encodes a single JsonRpcMessage for transport.
     *
     * @param JsonRpcMessage $message The message to encode.
     * @return string The JSON encoded message string.
     * @throws TransportException If the encoded message exceeds MAX_MESSAGE_SIZE.
     */
    protected function encodeMessage(JsonRpcMessage $message): string
    {
        $json = $message->toJson();

        if (strlen($json) > static::MAX_MESSAGE_SIZE) {
            throw new TransportException("Encoded message exceeds size limit of " . static::MAX_MESSAGE_SIZE . " bytes.");
        }

        return $json;
    }

    /**
     * Parses a raw string which may contain one or more JSON-RPC messages.
     *
     * This method attempts to decode the incoming raw data string. It expects
     * the raw data to be either a single JSON object (a single JSON-RPC request)
     * or a JSON array of JSON objects (a batch of JSON-RPC requests).
     *
     * - If $rawData represents a single JSON-RPC message, it's wrapped in an array.
     * - If $rawData represents a batch of JSON-RPC messages, each is processed.
     * - If $rawData is empty after trim, returns `null`.
     * - If $rawData has malformed JSON, throws `TransportException` with code `JsonRpcMessage::PARSE_ERROR`.
     * - If $rawData is valid JSON but not a valid JSON-RPC structure (e.g. not an object/array,
     *   or messages within a batch are invalid), throws `TransportException` with code `JsonRpcMessage::INVALID_REQUEST`.
     *
     * @param string $rawData The raw string data received from the transport.
     * @return JsonRpcMessage[]|null An array of JsonRpcMessage objects if parsing is successful and input is not empty,
     *                               or null if $rawData is empty after trimming.
     * @throws TransportException If parsing fails due to malformed JSON, invalid JSON-RPC structure, or excessive size.
     */
    protected function parseMessages(string $rawData): ?array
    {
        $trimmedData = trim($rawData);
        if (empty($trimmedData)) {
            // As per requirement: "If $rawData is empty after trim, return null."
            return null;
        }

        if (strlen($trimmedData) > static::MAX_MESSAGE_SIZE) {
            throw new TransportException(
                "Raw data exceeds size limit of " . static::MAX_MESSAGE_SIZE . " bytes.",
                JsonRpcMessage::INVALID_REQUEST
            );
        }

        $decodedData = json_decode($trimmedData, true);
        $jsonErrorCode = json_last_error();

        if ($jsonErrorCode !== JSON_ERROR_NONE) {
            throw new TransportException(
                "Failed to decode JSON: " . json_last_error_msg() . ". Data: " . substr($trimmedData, 0, 200) . "...",
                JsonRpcMessage::PARSE_ERROR
            );
        }

        if (!is_array($decodedData)) {
            throw new TransportException(
                "Decoded JSON is not an array or object. Data: " . substr($trimmedData, 0, 200) . "...",
                JsonRpcMessage::INVALID_REQUEST
            );
        }

        $messages = [];

        if (empty($decodedData)) { // Handles `[]` case, which is a valid empty batch.
            return [];
        }

        // Determine if it's a batch (indexed array) or single message (associative array)
        $isBatch = array_keys($decodedData) === range(0, count($decodedData) - 1);

        if ($isBatch) {
            // It's an array of messages (batch)
            if (empty($decodedData)) { // Already handled above, but as a safeguard for logic.
                return [];
            }
            foreach ($decodedData as $index => $messageData) {
                if (!is_array($messageData)) {
                    throw new TransportException(
                        "Invalid item in batch at index {$index}: not an object/array. Item: " . substr((string)json_encode($messageData), 0, 100),
                        JsonRpcMessage::INVALID_REQUEST
                    );
                }
                try {
                    $messageJson = json_encode($messageData);
                    if ($messageJson === false) {
                        // This case should be rare if $messageData is a valid array from json_decode
                        throw new TransportException(
                            "Failed to re-encode message in batch at index {$index}. Data: " . substr((string)json_encode($messageData), 0, 100) . "...",
                            JsonRpcMessage::INVALID_REQUEST // Or PARSE_ERROR, though it's an encoding issue here
                        );
                    }
                    $messages[] = JsonRpcMessage::fromJson($messageJson);
                } catch (\RuntimeException $e) {
                    // Assuming JsonRpcMessage::fromJson throws RuntimeException with appropriate codes
                    throw new TransportException(
                        "Error parsing message in batch at index {$index}: " . $e->getMessage() . ". Data: " . substr((string)json_encode($messageData), 0, 100) . "...",
                        $e->getCode() !== 0 ? $e->getCode() : JsonRpcMessage::INVALID_REQUEST, // Use exception code if available
                        $e
                    );
                } catch (\Exception $e) { // Catch any other unexpected errors during message construction
                    throw new TransportException(
                        "Unexpected error parsing message in batch at index {$index}: " . $e->getMessage() . ". Data: " . substr((string)json_encode($messageData), 0, 100) . "...",
                        JsonRpcMessage::INTERNAL_ERROR,
                        $e
                    );
                }
            }
        } else {
            // It's a single message (associative array)
            // Use $trimmedData directly as it's the original JSON string for the single message.
            try {
                $messages[] = JsonRpcMessage::fromJson($trimmedData);
            } catch (\RuntimeException $e) {
                // Assuming JsonRpcMessage::fromJson throws RuntimeException with appropriate codes
                throw new TransportException(
                    "Error parsing single message: " . $e->getMessage() . ". Data: " . substr($trimmedData, 0, 200) . "...",
                    $e->getCode() !== 0 ? $e->getCode() : JsonRpcMessage::INVALID_REQUEST, // Use exception code if available
                    $e
                );
            } catch (\Exception $e) { // Catch any other unexpected errors
                throw new TransportException(
                    "Unexpected error parsing single message: " . $e->getMessage() . ". Data: " . substr($trimmedData, 0, 200) . "...",
                    JsonRpcMessage::INTERNAL_ERROR,
                    $e
                );
            }
        }

        return $messages;
    }

    /**
     * Decodes and validates a received JSON string into a JsonRpcMessage.
     *
     * @deprecated This method is deprecated in favor of `parseMessages()`, which can handle
     *             single messages as well as batches and provides more robust error handling
     *             for structured data. Consider using `parseMessages()` and then extracting
     *             the first message if only one is expected.
     * @param string $data The raw JSON data received from the transport.
     * @return JsonRpcMessage|null The decoded message, or null if decoding fails (e.g., invalid JSON).
     * @throws TransportException If the received data string exceeds MAX_MESSAGE_SIZE before decoding.
     */
    protected function decodeMessage(string $data): ?JsonRpcMessage
    {
        if (strlen($data) > static::MAX_MESSAGE_SIZE) {
            throw new TransportException("Received data exceeds size limit of " . static::MAX_MESSAGE_SIZE . " bytes.");
        }

        try {
            return JsonRpcMessage::fromJson($data);
        } catch (\Exception $e) {
            // Log but don't throw - allow server to handle invalid messages
            $this->log("Error parsing received JSON message: " . $e->getMessage() . ". Data: " . substr($data, 0, 200) . "...");
            return null;
        }
    }

    /**
     * Logs a message related to the transport's operation.
     *
     * This default implementation writes messages to PHP's error_log.
     * Subclasses can override this to use a more sophisticated logging mechanism.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        error_log(get_class($this) . ": " . $message);
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
    }
}
