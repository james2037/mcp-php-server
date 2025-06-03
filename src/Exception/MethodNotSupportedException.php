<?php

namespace MCP\Server\Exception;

/**
 * Exception thrown when a capability is asked to handle a method it doesn't support.
 * This typically corresponds to the JSON-RPC error code -32601 (Method not found).
 */
class MethodNotSupportedException extends \Exception
{
    /**
     * Constructor for MethodNotSupportedException.
     *
     * @param string $method The name of the unsupported method.
     */
    public function __construct(string $method)
    {
        parent::__construct("Method not supported: $method");
    }
}
