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
    /** @var bool Whether debug logging to STDERR is enabled. */
    private bool $debugEnabled = false;

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
            $trimmedLine = trim($line);
            $isLikelyBatch = strpos($trimmedLine, '[') === 0;

            // Conditional decoding: objects for batch (false), associative arrays for single (true).
            // This $decodedInput is used for structural checks before passing the raw string.
            $decodedInput = json_decode($trimmedLine, !$isLikelyBatch, 512, JSON_THROW_ON_ERROR);

            if ($isLikelyBatch) {
                // $decodedInput should be an array (of objects, due to 'false' in json_decode).
                if (is_array($decodedInput)) {
                    // Pass the original string to fromJsonArray, as it expects a string.
                    return JsonRpcMessage::fromJsonArray($trimmedLine);
                } else {
                    // $trimmedLine started with '[' but didn't decode to a PHP array.
                    throw new \RuntimeException(
                        'Invalid JSON-RPC batch structure. Expected a JSON array after decoding.',
                        JsonRpcMessage::PARSE_ERROR
                    );
                }
            } else { // Single request
                if (is_array($decodedInput) && !empty($decodedInput)) { // Check for non-empty assoc array
                    $message = JsonRpcMessage::fromJson($trimmedLine);
                    return [$message];
                } elseif (is_object($decodedInput)) { // Should not happen if assoc is true typically, but check for robustness
                    $message = JsonRpcMessage::fromJson($trimmedLine);
                    return [$message];
                } else {
                    // $decodedInput is not a non-empty associative array or an object.
                    // This uses the generic message that tests expect.
                    throw new \RuntimeException(
                        'Invalid JSON-RPC message structure. Expected object or array of objects.',
                        JsonRpcMessage::PARSE_ERROR
                    );
                }
            }
        } catch (\JsonException $e) { // Catch JSON parsing errors first
            throw new \RuntimeException(
                'JSON Parse Error: ' . $e->getMessage(),
                JsonRpcMessage::PARSE_ERROR,
                $e
            );
        } catch (\Exception $e) { // Catch other exceptions from JsonRpcMessage processing
            $this->errorLog("Error processing received message: " . $e->getMessage());
            throw new \RuntimeException(
                'Error parsing JSON-RPC message: ' . $e->getMessage(),
                JsonRpcMessage::INVALID_REQUEST,
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
     * Enables or disables debug logging to STDERR.
     *
     * @param bool $enabled True to enable debug logging, false to disable.
     */
    public function setDebug(bool $enabled): void
    {
        $this->debugEnabled = $enabled;
    }

    /**
     * Logs a debug message to STDERR if debug mode is enabled, followed by a newline.
     *
     * @param string $message The debug message to log.
     */
    public function debugLog(string $message): void
    {
        if ($this->debugEnabled) {
            fwrite($this->stderr, "[DEBUG] " . $message . "\n");
            fflush($this->stderr);
        }
    }

    /**
     * Logs an error message to STDERR, followed by a newline.
     * This log always writes, regardless of debug mode.
     *
     * @param string $message The error message to log.
     */
    public function errorLog(string $message): void
    {
        fwrite($this->stderr, "[ERROR] " . $message . "\n");
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
