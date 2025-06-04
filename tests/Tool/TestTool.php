<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('test.tool', 'A test tool')]
class TestTool extends Tool
{
    /**
     * @return ContentItemInterface
     */
    protected function doExecute(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet')]
        array $arguments
    ): \MCP\Server\Tool\Content\ContentItemInterface {
        return $this->text('Hello ' . $arguments['name']);
    }
}
