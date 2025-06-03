<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute('test.tool', 'A test tool')]
class TestTool extends Tool
{
    /**
     * Greets the given name.
     * @param string $name Name to greet.
     * @return TextContent[]
     */
    protected function executeTool(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet', required: true)]
        string $name
    ): array {
        return [$this->createTextContent('Hello ' . $name)];
    }

    protected function doExecute(array $arguments): array
    {
        // Base validateArguments should have ensured 'name' is present and is a string.
        return $this->executeTool($arguments['name']);
    }
}
