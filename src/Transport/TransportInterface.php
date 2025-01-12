<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;

interface TransportInterface
{
    /**
     * Read a message from the transport
     * Returns null if no message is available
     */
    public function receive(): ?JsonRpcMessage;

    /**
     * Send a message through the transport
     */
    public function send(JsonRpcMessage $message): void;

    /**
     * Log a message (typically debug/error info)
     * Implementation should handle where this goes (e.g. stderr for stdio)
     */
    public function log(string $message): void;

    public function isClosed(): bool;
}
