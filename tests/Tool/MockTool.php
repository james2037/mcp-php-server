<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     */
    protected function doExecute(array $arguments): array
    {
        return [$this->text('Hello World')];
    }
}
