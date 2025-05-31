<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

class StdioTransport extends AbstractTransport
{
    private $_stdin;
    private $_stdout;
    private $_stderr;

    public function __construct()
    {
        $this->_stdin = $this->getInputStream();
        $this->_stdout = $this->getOutputStream();
        $this->_stderr = $this->getErrorStream();
    }

    public function receive(): ?array
    {
        $line = fgets($this->_stdin);

        if ($line === false) {
            // Stream closed
            return [];
        }

        $line = trim($line);
        if ($line === '') {
            // No message received, transport open
            return null;
        }

        try {
            // Attempt to decode as JSON. We need to inspect its structure.
            $decodedInput = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if ($decodedInput === null && json_last_error() !== JSON_ERROR_NONE) {
                // This case should ideally be caught by JSON_THROW_ON_ERROR,
                // but as a safeguard or for older PHP versions.
                throw new \RuntimeException('JSON Parse Error: ' . json_last_error_msg(), JsonRpcMessage::PARSE_ERROR);
            }

            // Check for batch request: a non-empty numerically indexed array
            if (is_array($decodedInput)
                && (count($decodedInput) === 0 || array_keys($decodedInput) === range(0, count($decodedInput) - 1))
            ) {
                if (empty($decodedInput)) {
                    // Empty array received, spec implies it's invalid for batch, but we should return empty messages.
                    return [];
                }
                // It's a batch, let fromJsonArray handle full parsing and validation from original string
                return JsonRpcMessage::fromJsonArray($line);
            } elseif (is_object($decodedInput) || (is_array($decodedInput) && !empty($decodedInput))) {
                // It's a single request (object) or potentially an associative array representing a single request
                // Let fromJson handle full parsing and validation from original string
                $message = JsonRpcMessage::fromJson($line);
                return [$message];
            } else {
                // Invalid structure that is not explicitly an empty array or a valid single/batch candidate
                throw new \RuntimeException('Invalid JSON-RPC message structure.', JsonRpcMessage::PARSE_ERROR);
            }
        } catch (\JsonException $e) {
            throw new \RuntimeException('JSON Parse Error: ' . $e->getMessage(), JsonRpcMessage::PARSE_ERROR, $e);
        } catch (\Exception $e) {
            // Catch other exceptions from JsonRpcMessage::fromJson or fromJsonArray
            $this->log("Error processing message: " . $e->getMessage());
            // Depending on desired behavior, re-throw or return error specific message
            // For now, let's re-throw as a runtime exception consistent with parse errors
            throw new \RuntimeException('Error parsing JSON-RPC message: ' . $e->getMessage(), JsonRpcMessage::INVALID_REQUEST, $e);
        }
    }

    public function send(JsonRpcMessage|array $message): void
    {
        $json = '';
        if (is_array($message)) {
            $json = JsonRpcMessage::toJsonArray($message);
        } else {
            $json = $message->toJson();
        }

        if (strpos($json, "\n") !== false) {
            throw new TransportException("Message contains newlines");
        }

        $written = fwrite($this->_stdout, $json . "\n");  // Now using the stored stream
        if ($written === false || $written !== strlen($json) + 1) {
            throw new TransportException("Failed to write complete message");
        }

        fflush($this->_stdout);
    }

    public function log(string $message): void
    {
        fwrite($this->_stderr, $message . "\n");  // Now using the stored stream
        fflush($this->_stderr);
    }

    protected function getInputStream()
    {
        return STDIN;
    }

    protected function getOutputStream()
    {
        return STDOUT;
    }

    protected function getErrorStream()
    {
        return STDERR;
    }

    public function isClosed(): bool
    {
        return feof($this->_stdin);
    }
}
