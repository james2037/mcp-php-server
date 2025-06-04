<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\AbstractTransport;
use MCP\Server\Message\JsonRpcMessage;

class TestableAbstractTransport extends AbstractTransport
{
    // Dummy implementations for abstract methods not relevant to parseMessages testing
    public function receive(): array|null|false
    {
        // This method needs to use parseMessages to properly test its integration,
        // but for direct testing of parseMessages via callParseMessages,
        // its immediate behavior is less critical.
        // However, to avoid fatal errors if it were called, provide a stub.
        return null;
    }

    public function send(JsonRpcMessage|array $message): void
    {
        // No-op for these tests
    }

    // log() is already implemented in AbstractTransport and will use error_log by default.
    // We can override it if we want to capture logs during tests.
    /** @var string[] */
    private array $loggedMessages = [];

    public function log(string $message): void
    {
        $this->loggedMessages[] = $message;
        // parent::log($message); // Optionally call parent to also use error_log
    }

    /** @return string[] */
    public function getLoggedMessages(): array
    {
        return $this->loggedMessages;
    }

    public function isClosed(): bool
    {
        return false; // Not relevant for parseMessages tests
    }

    public function isStreamOpen(): bool
    {
        return false; // Not relevant for parseMessages tests
    }

    /**
     * Exposes the protected parseMessages method for testing.
     *
     * @param string $rawData The raw data to parse.
     * @return JsonRpcMessage[]|null
     * @throws \MCP\Server\Exception\TransportException
     */
    public function callParseMessages(string $rawData): ?array
    {
        return $this->parseMessages($rawData);
    }

    /**
     * Exposes the protected MAX_MESSAGE_SIZE constant for testing.
     * @return int
     */
    public static function getExposedMaxMessageSize(): int
    {
        return static::MAX_MESSAGE_SIZE; // Or self::MAX_MESSAGE_SIZE
    }
}
