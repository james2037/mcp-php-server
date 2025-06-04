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
class StdioTransport extends AbstractTransport
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
     * Reads a line from STDIN and parses it using `AbstractTransport::parseMessages()`.
     *
     * @return JsonRpcMessage[]|null|false An array of `JsonRpcMessage` objects if successful,
     *                                     `null` if an empty line is read (and transport is open),
     *                                     or `false` if STDIN is closed (EOF).
     * @throws TransportException If `parseMessages()` encounters an error (e.g., malformed JSON,
     *                            invalid JSON-RPC structure, message too large).
     */
    public function receive(): array|null|false
    {
        $line = fgets($this->stdin);

        if ($line === false) {
            // EOF or error on stream
            return false; // TransportInterface dictates false for closed transport
        }

        // Note: parseMessages handles trimming and empty string checks.
        // If $line is just "\n", trim($line) will be empty, and parseMessages will return null.
        // If $line contains actual content, parseMessages will process it.
        try {
            return $this->parseMessages($line);
        } catch (TransportException $e) {
            // Log the exception message from parseMessages and re-throw.
            $this->log("TransportException in StdioTransport::receive: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
            throw $e;
        }
        // No need to catch other generic \Exception here, as parseMessages is expected
        // to throw TransportException for known parsing issues.
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

        if (strpos($json, "\n") !== false) {
            throw new TransportException("Message to be sent contains internal newlines, which is not allowed for StdioTransport.");
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
