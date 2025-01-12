<?php

namespace MCP\Server\Capability;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\MethodNotSupportedException;

interface CapabilityInterface
{
    /**
     * Get the capability description for server initialization
     * This goes into the ServerCapabilities object during initialize
     */
    public function getCapabilities(): array;

    /**
     * Check if this capability can handle the given message
     */
    public function canHandleMessage(JsonRpcMessage $message): bool;

    /**
     * Handle an incoming request or notification
     *
     * @throws MethodNotSupportedException if method not supported
     * @throws \Exception on other errors
     */
    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage;

    /**
     * Called when server is initializing
     * Can be used to set up resources, validate configuration, etc.
     */
    public function initialize(): void;

    /**
     * Called when server is shutting down
     * Can be used to clean up resources
     */
    public function shutdown(): void;
}
