<?php

declare(strict_types=1);

namespace MCP\Server\Exception;

/**
 * Exception thrown when a JSON-RPC request has invalid parameters.
 * This corresponds to the JSON-RPC error code -32602.
 */
class InvalidParamsException extends \Exception
{
}
