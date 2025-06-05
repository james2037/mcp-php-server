<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\StdioTransport;
use MCP\Server\Message\JsonRpcMessage;

// Though not directly used, it's contextually relevant

class TestableStdioTransport extends StdioTransport
{
    /** @var resource */
    private $input; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore
    /** @var resource */
    private $output; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore
    /** @var resource */
    private $error; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore
    private int $receiveCallCount = 0;
    /** @var array<int, array<int, mixed>|null> */
    private array $receiveOverrideQueue = [];
    private ?\Throwable $exceptionToThrowOnReceiveOnce = null;

    public function __construct()
    {
        // Create memory streams for testing
        $inputHandle = fopen('php://memory', 'r+');
        if ($inputHandle === false) {
            throw new \RuntimeException('Failed to open input memory stream for TestableStdioTransport');
        }
        $this->input = $inputHandle;

        $outputHandle = fopen('php://memory', 'r+');
        if ($outputHandle === false) {
            throw new \RuntimeException('Failed to open output memory stream for TestableStdioTransport');
        }
        $this->output = $outputHandle;

        $errorHandle = fopen('php://memory', 'r+');
        if ($errorHandle === false) {
            throw new \RuntimeException('Failed to open error memory stream for TestableStdioTransport');
        }
        $this->error = $errorHandle;

        // Call parent constructor after initializing our streams
        // StdioTransport's constructor calls getInputStream etc.
        // so our overridden methods will be used.
        parent::__construct();
    }

    // These override parent methods to use our memory streams
    public function getInputStream()
    {
        return $this->input;
    }

    public function getOutputStream()
    {
        return $this->output;
    }

    public function getErrorStream()
    {
        return $this->error;
    }

    // Helper methods for testing
    public function writeToInput(string $data): void
    {
        fseek($this->input, 0, SEEK_END);
        fwrite($this->input, $data . "\n");
        fseek($this->input, 0);
    }

    public function readFromOutput(): string
    {
        fseek($this->output, 0);
        $content = stream_get_contents($this->output);
        ftruncate($this->output, 0); // Clear the stream for next read
        fseek($this->output, 0);
        return $content ?: ''; // Ensure string return
    }

    public function readFromError(): string
    {
        fseek($this->error, 0);
        $content = stream_get_contents($this->error);
        ftruncate($this->error, 0); // Clear the stream
        fseek($this->error, 0);
        return $content ?: ''; // Ensure string return
    }

    /** @return array<int, array<string, mixed>> */
    public function readMultipleJsonOutputs(): array
    {
        fseek($this->output, 0);
        $content = stream_get_contents($this->output);
        ftruncate($this->output, 0);
        fseek($this->output, 0);

        if ($content === false) {
            // If stream_get_contents fails, treat as no output.
            return [];
        }

        // Now $content is a string.
        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", trim($content)); // trim is now safe
        $decodedOutputs = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Failed to decode JSON line: " . $line . " - Error: " . json_last_error_msg());
            }
            $decodedOutputs[] = $decoded;
        }
        return $decodedOutputs;
    }

    // Cleanup resources
    public function __destruct()
    {
        if (is_resource($this->input)) {
            fclose($this->input);
        }
        if (is_resource($this->output)) {
            fclose($this->output);
        }
        if (is_resource($this->error)) {
            fclose($this->error);
        }
    }

    /**
     * @return array<int, \MCP\Server\Message\JsonRpcMessage>|null
     */
    public function receive(): ?array
    {
        $this->receiveCallCount++;

        if ($this->exceptionToThrowOnReceiveOnce !== null) {
            $e = $this->exceptionToThrowOnReceiveOnce;
            $this->exceptionToThrowOnReceiveOnce = null; // Consume it
            throw $e;
        }

        if (!empty($this->receiveOverrideQueue)) {
            return array_shift($this->receiveOverrideQueue);
        }
        return parent::receive();
    }

    public function getReceiveCallCount(): int
    {
        return $this->receiveCallCount;
    }

    /**
     * @param array<int, mixed>|null $messages The array of messages or null to return from receive().
     *                                         The items in the array can be mixed types for testing.
     */
    public function queueReceiveOverride(?array $messages): void
    {
        $this->receiveOverrideQueue[] = $messages;
    }

    public function throwOnNextReceive(\Throwable $e): void
    {
        $this->exceptionToThrowOnReceiveOnce = $e;
    }
}
