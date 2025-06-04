<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Helpers;

use MCP\Server\Tool\Tool;

class NoAttributeTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        return []; // Dummy implementation
    }
}
