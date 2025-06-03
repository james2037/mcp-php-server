<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent; // Though not used, for consistency
use MCP\Server\Tool\Tool;

#[ToolAttribute('failing', 'Failing Tool')]
class FailingMockTool extends Tool
{
    /**
     * This tool always throws an exception.
     * @return TextContent[] Never returns normally.
     * @throws \RuntimeException Always.
     */
    protected function executeTool(): array
    {
        throw new \RuntimeException('Tool execution failed');
    }

    protected function doExecute(array $arguments): array
    {
        // No arguments expected or used by executeTool for this tool
        return $this->executeTool();
    }
}
