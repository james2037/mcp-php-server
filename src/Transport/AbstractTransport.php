<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

abstract class AbstractTransport implements TransportInterface
{
    /**
     * Maximum allowed message size in bytes (10MB)
     */
    protected const MAX_MESSAGE_SIZE = 10 * 1024 * 1024;

    /**
     * Validates and encodes a message for transport
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
     * Decodes and validates a received message
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
     * Logs a message. Default implementation writes to error_log.
     */
    public function log(string $message): void
    {
        error_log($message);
    }
}
