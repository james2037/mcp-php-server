<?php

namespace MCP\Server\Tests;

use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Message\JsonRpcMessage;

class TestCapability implements CapabilityInterface
{
    private array $expectedResponses = [];
    private array $receivedMessages = [];

    public function addExpectedResponse(string $method, ?JsonRpcMessage $response): void
    {
        $this->expectedResponses[$method] = $response;
    }

    public function getReceivedMessages(): array
    {
        return $this->receivedMessages;
    }

    public function resetReceivedMessages(): void
    {
        $this->receivedMessages = [];
    }

    public function getCapabilities(): array
    {
        return ['test' => ['enabled' => true]];
    }

    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return isset($this->expectedResponses[$message->method]);
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        $this->receivedMessages[] = $message;
        return $this->expectedResponses[$message->method] ?? null;
    }

    public function initialize(): void
    {
    }
    public function shutdown(): void
    {
    }
}
