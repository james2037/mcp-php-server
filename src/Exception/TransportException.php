<?php

namespace MCP\Server\Exception;

/**
 * Exception thrown when there are transport-level issues,
 * such as errors in reading from or writing to the communication channel.
 */
class TransportException extends \Exception
{
}
