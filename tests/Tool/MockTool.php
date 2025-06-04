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
     * @return ContentItemInterface
     */
    protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
    {
        return $this->text('Hello World');
    }
}
