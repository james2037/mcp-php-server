<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\StdioTransport;
use MCP\Server\Message\JsonRpcMessage;

// Though not directly used, it's contextually relevant

class TestableStdioTransport extends StdioTransport
{
    private $input; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore
    private $output; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore
    private $error; // PHPCS: PSR2.Classes.PropertyDeclaration.Underscore

    public function __construct()
    {
        // Create memory streams for testing
        $this->input = fopen('php://memory', 'r+');
        $this->output = fopen('php://memory', 'r+');
        $this->error = fopen('php://memory', 'r+');

        // Call parent constructor after initializing our streams
        // StdioTransport's constructor calls getInputStream etc.
        // so our overridden methods will be used.
        parent::__construct();
    }

    // These override parent methods to use our memory streams
    protected function getInputStream()
    {
        return $this->input;
    }

    protected function getOutputStream()
    {
        return $this->output;
    }

    protected function getErrorStream()
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

    public function readMultipleJsonOutputs(): array
    {
        fseek($this->output, 0);
        $content = stream_get_contents($this->output);
        ftruncate($this->output, 0);
        fseek($this->output, 0);

        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", trim($content));
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
}
