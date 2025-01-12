<?php

namespace MCP\Server\Exception;

/**
 * Exception thrown when a capability is asked to handle a method it doesn't support
 */
class MethodNotSupportedException extends \Exception
{
    public function __construct(string $method)
    {
        parent::__construct("Method not supported: $method");
    }
}
