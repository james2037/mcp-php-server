<?php

namespace MCP\Server\Tests\Util;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;

class MockTransport implements TransportInterface
{
    private array $incomingMessages = [];
    private array $sentMessages = [];

    public function queueIncoming(JsonRpcMessage $message): void
    {
        $this->incomingMessages[] = $message;
    }

    public function receive(): ?JsonRpcMessage
    {
        return array_shift($this->incomingMessages);
    }

    public function send(JsonRpcMessage $message): void
    {
        $this->sentMessages[] = $message;
    }

    public function getLastSent(): ?JsonRpcMessage
    {
        return end($this->sentMessages) ?: null;
    }

    public function isClosed(): bool
    {
        // The MockTransport is always open for business.
        return false;
    }

    public function log(string $message): void
    {
        // No-op for testing
    }
}
