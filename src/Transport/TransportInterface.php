<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;

interface TransportInterface
{
    /**
     * Read a message from the transport
     * Returns JsonRpcMessage[] if a message or messages are received, null if no message is available, or an empty array if the transport is closed.
     *
     * @return JsonRpcMessage[]|null
     */
    public function receive(): ?array;

    /**
     * Send a message through the transport
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $message
     */
    public function send(JsonRpcMessage|array $message): void;

    /**
     * Log a message (typically debug/error info)
     * Implementation should handle where this goes (e.g. stderr for stdio)
     */
    public function log(string $message): void;

    public function isClosed(): bool;
}
