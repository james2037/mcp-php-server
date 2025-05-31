<?php

/**
 * This file contains the TransportInterface.
 */

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;

/**
 * Interface for message transports.
 */
interface TransportInterface
{
    /**
     * Reads a message from the transport.
     *
     * Returns JsonRpcMessage[] if a message or messages are received,
     * null if no message is available, or an empty array if the
     * transport is closed.
     *
     * @return JsonRpcMessage[]|null An array of messages, null, or an empty array.
     */
    public function receive(): ?array;

    /**
     * Sends a message or an array of messages through the transport.
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $message The message or messages to send.
     * @return void
     */
    public function send(JsonRpcMessage|array $message): void;

    /**
     * Logs a message.
     *
     * Implementation should handle where this goes (e.g., stderr for stdio).
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log(string $message): void;

    /**
     * Checks if the transport is closed.
     *
     * @return bool True if the transport is closed, false otherwise.
     */
    public function isClosed(): bool;
}
