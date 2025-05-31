<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;

#[ToolAttribute('failing', 'Failing Tool')]
class FailingMockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        throw new \RuntimeException('Tool execution failed');
    }
}
