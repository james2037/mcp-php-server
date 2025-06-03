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
        $nameValue = $arguments['name'] ?? ''; // Default to empty if not provided, though 'name' is required by schema
        $titleValue = $arguments['title'] ?? 'friend';

        // Ensure they are strings before interpolation for PHPStan
        $nameStr = is_scalar($nameValue) ? (string)$nameValue : '';
        $titleStr = is_scalar($titleValue) ? (string)$titleValue : 'friend'; // title can default to 'friend'

        return [$this->createTextContent("Hello {$titleStr} {$nameStr}")];
    }
}
