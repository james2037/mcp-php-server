<?php

namespace MCP\Server\Tests\Util;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Transport\TransportInterface;

class MockTransport implements TransportInterface
{
    /** @var array<int, array<int, JsonRpcMessage>> */
    private array $incomingMessages = []; // Stores batches of messages (JsonRpcMessage[])
    /** @var array<int, JsonRpcMessage> */
    private array $sentMessages = [];     // Stores individual JsonRpcMessage objects

    /**
     * Queues a batch of messages that receive() will return once.
     * Each call to this method adds one "line" or "packet" the server will read.
     *
     * @param JsonRpcMessage[] $messagesBatch An array of JsonRpcMessage objects.
     */
    public function queueIncomingMessages(array $messagesBatch): void
    {
        $this->incomingMessages[] = $messagesBatch;
    }

    /**
     * @return JsonRpcMessage[]|null
     */
    public function receive(): ?array
    {
        if (empty($this->incomingMessages)) {
            return null; // No more messages or batches to receive
        }
        return array_shift($this->incomingMessages);
    }

    /**
     * @param JsonRpcMessage|JsonRpcMessage[] $messageOrMessages
     */
    public function send(JsonRpcMessage|array $messageOrMessages): void
    {
        if (is_array($messageOrMessages)) {
            // If it's an array of JsonRpcMessage objects (batch response/notifications)
            $this->sentMessages = array_merge($this->sentMessages, $messageOrMessages);
        } else {
            // If it's a single JsonRpcMessage object
            $this->sentMessages[] = $messageOrMessages;
        }
    }

    public function getLastSent(): ?JsonRpcMessage
    {
        return end($this->sentMessages) ?: null;
    }

    /**
     * @return JsonRpcMessage[]
     */
    public function getAllSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function reset(): void
    {
        $this->incomingMessages = [];
        $this->sentMessages = [];
    }

    public function isClosed(): bool
    {
        // MockTransport can be considered closed if no more incoming messages are queued.
        // This helps server loop to terminate in tests if not explicitly given a shutdown.
        return empty($this->incomingMessages);
    }

    public function log(string $message): void
    {
        // No-op for testing, or could store logs if needed for assertions
        // error_log("MockTransport Log: " . $message); // For debugging tests
    }

    /**
     * Checks if the transport stream is currently open.
     * For this mock, defaults to false. Can be made configurable if needed.
     *
     * @return bool Always false by default.
     */
    public function isStreamOpen(): bool
    {
        return false; // Default mock behavior
    }
}
