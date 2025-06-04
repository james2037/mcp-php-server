<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('failing', 'Failing Tool')]
class FailingMockTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     * @throws \RuntimeException Always throws.
     */
    protected function doExecute(array $arguments): array
    {
        throw new \RuntimeException('Tool execution failed');
    }
}
