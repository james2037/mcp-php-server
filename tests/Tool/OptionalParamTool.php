<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('greeter', 'A friendly greeter')]
class OptionalParamTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet')]
        #[ParameterAttribute('title', type: 'string', description: 'Optional title', required: false)]
        array $arguments
    ): array {
        $title = $arguments['title'] ?? 'friend';
        return [$this->createTextContent("Hello {$title} {$arguments['name']}")];
    }
}
