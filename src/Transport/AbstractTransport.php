<?php

/**
 * This file contains the AbstractTransport class.
 */

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

/**
 * Abstract base class for message transports.
 */
abstract class AbstractTransport implements TransportInterface
{
    /**
     * Maximum allowed message size in bytes (10MB).
     */
    protected const MAX_MESSAGE_SIZE = 10 * 1024 * 1024;

    /**
     * Validates and encodes a message for transport.
     *
     * @param JsonRpcMessage $message The message to encode.
     * @return string The JSON encoded message.
     * @throws TransportException If the message exceeds the size limit.
     */
    protected function encodeMessage(JsonRpcMessage $message): string
    {
        $json = $message->toJson();

        if (strlen($json) > static::MAX_MESSAGE_SIZE) {
            throw new TransportException("Message exceeds size limit");
        }

        return $json;
    }

    /**
     * Decodes and validates a received message.
     *
     * @param string $data The raw data received from the transport.
     * @return JsonRpcMessage|null The decoded message or null if decoding fails.
     * @throws TransportException If the received data exceeds the size limit.
     */
    protected function decodeMessage(string $data): ?JsonRpcMessage
    {
        if (strlen($data) > static::MAX_MESSAGE_SIZE) {
            throw new TransportException("Message exceeds size limit");
        }

        try {
            return JsonRpcMessage::fromJson($data);
        } catch (\Exception $e) {
            // Log but don't throw - allow server to handle invalid messages
            $this->log("Error parsing message: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Logs a message.
     *
     * Default implementation writes to error_log.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log(string $message): void
    {
        error_log($message);
    }
}
