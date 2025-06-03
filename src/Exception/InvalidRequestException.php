<?php

declare(strict_types=1);

namespace MCP\Server\Exception;

/**
 * Exception thrown when a JSON-RPC request is malformed or invalid.
 * This corresponds to the JSON-RPC error code -32600.
 */
class InvalidRequestException extends \Exception
{
}
