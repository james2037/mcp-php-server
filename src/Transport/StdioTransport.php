<?php

/**
 * This file contains the StdioTransport class.
 */

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

/**
 * Implements a transport using STDIN, STDOUT, and STDERR.
 */
class StdioTransport extends AbstractTransport
{
    private $stdin;
    private $stdout;
    private $stderr;

    /**
     * Constructs a new StdioTransport instance.
     * Initializes STDIN, STDOUT, and STDERR streams.
     */
    public function __construct()
    {
        $this->stdin = $this->getInputStream();
        $this->stdout = $this->getOutputStream();
        $this->stderr = $this->getErrorStream();
    }

    /**
     * Receives a message from STDIN.
     *
     * @return JsonRpcMessage[]|null An array of messages, null if no message,
     *                               or empty array if stream is closed.
     * @throws \RuntimeException If there is a JSON parsing error or invalid message structure.
     */
    public function receive(): ?array
    {
        $line = fgets($this->stdin);

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

            // Check for batch request: a non-empty numerically indexed array
            if (
                is_array($decodedInput)
                && (count($decodedInput) === 0
                    || array_keys($decodedInput) === range(0, count($decodedInput) - 1))
            ) {
                if (empty($decodedInput)) {
                    // Empty array received, spec implies it's invalid for batch,
                    // but we should return empty messages.
                    return [];
                }
                // It's a batch, let fromJsonArray handle full parsing
                // and validation from original string
                return JsonRpcMessage::fromJsonArray($line);
            } elseif (
                is_object($decodedInput)
                || (is_array($decodedInput) && !empty($decodedInput))
            ) {
                // It's a single request (object) or potentially an associative
                // array representing a single request.
                // Let fromJson handle full parsing and validation from original string
                $message = JsonRpcMessage::fromJson($line);
                return [$message];
            } else {
                // Invalid structure that is not explicitly an empty array
                // or a valid single/batch candidate
                throw new \RuntimeException(
                    'Invalid JSON-RPC message structure.',
                    JsonRpcMessage::PARSE_ERROR
                );
            }
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'JSON Parse Error: ' . $e->getMessage(),
                JsonRpcMessage::PARSE_ERROR,
                $e
            );
        } catch (\Exception $e) {
            // Catch other exceptions from JsonRpcMessage::fromJson or fromJsonArray
            $this->log("Error processing message: " . $e->getMessage());
            // Depending on desired behavior, re-throw or return error specific message
            // For now, let's re-throw as a runtime exception consistent with parse errors
            throw new \RuntimeException(
                'Error parsing JSON-RPC message: ' . $e->getMessage(),
                JsonRpcMessage::INVALID_REQUEST,
                $e
            );
        }
    }

    /**
     * Sends a JSON-RPC message or batch of messages to STDOUT.
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $message The message or messages to send.
     * @return void
     * @throws TransportException If the message contains newlines or fails to write.
     */
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

        $written = fwrite($this->stdout, $json . "\n");  // Now using the stored stream
        if ($written === false || $written !== strlen($json) + 1) {
            throw new TransportException("Failed to write complete message");
        }

        fflush($this->stdout);
    }

    /**
     * Logs a message to STDERR.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log(string $message): void
    {
        fwrite($this->stderr, $message . "\n");  // Now using the stored stream
        fflush($this->stderr);
    }

    /**
     * Gets the input stream (STDIN).
     *
     * @return resource The input stream.
     */
    protected function getInputStream()
    {
        return STDIN;
    }

    /**
     * Gets the output stream (STDOUT).
     *
     * @return resource The output stream.
     */
    protected function getOutputStream()
    {
        return STDOUT;
    }

    /**
     * Gets the error stream (STDERR).
     *
     * @return resource The error stream.
     */
    protected function getErrorStream()
    {
        return STDERR;
    }

    /**
     * Checks if the STDIN stream is closed.
     *
     * @return bool True if STDIN is closed, false otherwise.
     */
    public function isClosed(): bool
    {
        return feof($this->stdin);
    }

    /**
     * Checks if the transport stream is currently open.
     * For StdioTransport, this is always false as it doesn't maintain
     * a persistent open stream in the way HttpTransport (SSE) does.
     *
     * @return bool Always false.
     */
    public function isStreamOpen(): bool
    {
        return false;
    }
}
