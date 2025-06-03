<?php

/**
 * This file contains the TransportInterface.
 */

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;

/**
 * Defines the contract for message transports.
 *
 * Transports are responsible for the actual communication of JSON-RPC messages
 * between the server and the client. This includes receiving raw data,
 * parsing it into messages (or delegating parsing), sending messages,
 * logging transport-specific events, and managing the connection state.
 */
interface TransportInterface
{
    /**
     * Reads data from the transport and attempts to form one or more JsonRpcMessage objects.
     *
     * - If one or more complete messages are received and parsed successfully,
     *   it should return an array of `JsonRpcMessage` objects.
     * - If no message is currently available but the transport is still open
     *   (e.g., non-blocking read with no data), it should return `null`.
     * - If the transport is definitively closed (e.g., EOF on STDIN, HTTP connection ended
     *   before full message), it should return an empty array `[]`.
     *
     * Implementations are responsible for handling framing, decoding, and basic structural
     * validation of incoming messages.
     *
     * @return JsonRpcMessage[]|null An array of JsonRpcMessage objects,
     *                               null if no message is currently available,
     *                               or an empty array if the transport is closed.
     * @throws \MCP\Server\Exception\TransportException For critical transport errors
     *         (e.g., connection lost mid-message, unrecoverable framing issues).
     * @throws \RuntimeException For errors like JSON parsing failures if handled within receive.
     */
    public function receive(): ?array;

    /**
     * Sends a single JSON-RPC message or an array of JSON-RPC messages through the transport.
     *
     * @param JsonRpcMessage|JsonRpcMessage[] $message The message or array of messages to send.
     *                                                 If an array, it's treated as a batch.
     * @throws \MCP\Server\Exception\TransportException If sending the message fails
     *         (e.g., connection closed, write error).
     */
    public function send(JsonRpcMessage|array $message): void;

    /**
     * Logs a message specific to the transport's operation.
     *
     * Example: For StdioTransport, this might write to STDERR.
     * For HttpTransport, it might use a PSR-3 logger if integrated.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void;

    /**
     * Checks if the transport connection is considered closed.
     *
     * For connection-oriented transports (like STDIN/STDOUT), this might mean EOF.
     * For request-response transports (like HTTP), this might mean after a response
     * has been fully sent or a terminal error occurred.
     *
     * @return bool True if the transport is closed, false otherwise.
     */
    public function isClosed(): bool;

    /**
     * Checks if the transport is currently maintaining an open stream for continuous data flow.
     * This is particularly relevant for features like Server-Sent Events (SSE) in HTTP.
     * For transports like basic STDIN/STDOUT or standard HTTP POST, this might always return false.
     *
     * @return bool True if a stream is actively open, false otherwise.
     */
    public function isStreamOpen(): bool;

    /**
     * Hints to the transport that Server-Sent Events (SSE) are preferred for the response stream, if applicable.
     *
     * Transports that support SSE (e.g., HttpTransport) can use this to modify
     * how they format and send responses. Transports that do not support SSE can ignore this hint.
     *
     * @param bool $prefer True to indicate a preference for SSE streaming, false otherwise.
     */
    public function preferSseStream(bool $prefer = true): void;
}
