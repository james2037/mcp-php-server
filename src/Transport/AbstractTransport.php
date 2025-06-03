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
     * Decodes and validates a received JSON string into a JsonRpcMessage.
     *
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
