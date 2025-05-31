<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        return [$this->createTextContent('Hello World')];
    }
}
