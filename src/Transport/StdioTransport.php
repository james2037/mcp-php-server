<?php

/**
 * This file contains the StdioTransport class.
 */

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

/**
 * Implements a transport using STDIN, STDOUT, and STDERR for line-based JSON messaging.
 * Each JSON-RPC message (or batch of messages) is expected to be on a single line.
 */
class StdioTransport implements TransportInterface
{
    /** @var resource The standard input stream. */
    private $stdin;
    /** @var resource The standard output stream. */
    private $stdout;
    /** @var resource The standard error stream. */
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
     * Receives a line from STDIN and attempts to parse it as one or more JsonRpcMessage objects.
     *
     * Handles single JSON-RPC requests or batch requests (JSON array of requests).
     *
     * @return JsonRpcMessage[]|null An array of JsonRpcMessage objects if a line is successfully parsed.
     *                               Returns an empty array if STDIN is closed (EOF).
     *                               Returns null if an empty line is read (transport still open).
     * @throws \RuntimeException If there is a JSON parsing error or an invalid JSON-RPC message structure.
     */
    public function receive(): ?array
    {
        $line = fgets($this->stdin);

        if ($line === false) {
            return []; // Stream closed
        }

        $line = trim($line);
        if ($line === '') {
            return null; // No message received, transport open
        }

        try {
            $decodedInput = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            // Check for batch request: a non-empty numerically indexed array
            if (
                is_array($decodedInput)
                && (count($decodedInput) === 0
                    || array_keys($decodedInput) === range(0, count($decodedInput) - 1))
            ) {
                if (empty($decodedInput)) {
                    // Empty array received, spec implies it's invalid for batch,
                    // but JsonRpcMessage::fromJsonArray will handle this (likely an error or empty messages).
                    // For consistency, we let fromJsonArray parse it.
                    return JsonRpcMessage::fromJsonArray($line);
                }
                // It's a batch, let fromJsonArray handle full parsing
                // and validation from original string
                return JsonRpcMessage::fromJsonArray($line);
            } elseif (
                is_object($decodedInput) // Decoded as object if not assoc=true
                || (is_array($decodedInput) && !empty($decodedInput)) // Decoded as non-empty assoc array
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
                    'Invalid JSON-RPC message structure. Expected object or array of objects.',
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
            $this->log("Error processing received message: " . $e->getMessage());
            // Depending on desired behavior, re-throw or return error specific message
            // For now, let's re-throw as a runtime exception consistent with parse errors
            throw new \RuntimeException(
                'Error parsing JSON-RPC message: ' . $e->getMessage(),
                JsonRpcMessage::INVALID_REQUEST, // Or PARSE_ERROR if more appropriate
                $e
            );
        }
    }

    /**
     * Sends a JSON-RPC message or batch of messages to STDOUT, followed by a newline.
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $message The JsonRpcMessage object or array of JsonRpcMessage objects to send.
     * @throws TransportException If the message contains internal newlines (which would break line-based transport)
     *                            or if writing to STDOUT fails.
     */
    public function send(JsonRpcMessage|array $message): void
    {
        $json = '';
        if (is_array($message)) {
            $json = JsonRpcMessage::toJsonArray($message);
        } else {
            $json = $message->toJson();
        }

        $written = fwrite($this->stdout, $json . "\n");
        if ($written === false || $written < strlen($json) + 1) { // Check if less than expected was written
            throw new TransportException("Failed to write complete message to STDOUT.");
        }

        fflush($this->stdout);
    }

    /**
     * Logs a message to STDERR, followed by a newline.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        fwrite($this->stderr, $message . "\n");
        fflush($this->stderr);
    }

    /**
     * Gets the input stream, defaulting to STDIN.
     * Protected to allow overriding in tests.
     *
     * @return resource The input stream.
     */
    protected function getInputStream()
    {
        return STDIN;
    }

    /**
     * Gets the output stream, defaulting to STDOUT.
     * Protected to allow overriding in tests.
     *
     * @return resource The output stream.
     */
    protected function getOutputStream()
    {
        return STDOUT;
    }

    /**
     * Gets the error stream, defaulting to STDERR.
     * Protected to allow overriding in tests.
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
