<?php

namespace MCP\Server\Capability;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\MethodNotSupportedException;

/**
 * Defines the contract for server capabilities.
 * Capabilities are modules that extend the server's functionality.
 */
interface CapabilityInterface
{
    /**
     * Returns the description of the capability.
     * This description is used during server initialization.
     *
     * @return array<string, mixed> The capability description.
     */
    public function getCapabilities(): array;

    /**
     * Checks if this capability can handle the given JSON-RPC message.
     *
     * @param JsonRpcMessage $message The message to check.
     * @return bool True if the capability can handle the message, false otherwise.
     */
    public function canHandleMessage(JsonRpcMessage $message): bool;

    /**
     * Handles an incoming JSON-RPC request or notification.
     *
     * @param JsonRpcMessage $message The message to handle.
     * @return JsonRpcMessage|null A response message, or null for notifications.
     * @throws MethodNotSupportedException if the method is not supported by this capability.
     * @throws \Exception on other processing errors.
     */
    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage;

    /**
     * Initializes the capability.
     * This method is called when the server is initializing.
     */
    public function initialize(): void;

    /**
     * Shuts down the capability.
     * This method is called when the server is shutting down.
     */
    public function shutdown(): void;
}
