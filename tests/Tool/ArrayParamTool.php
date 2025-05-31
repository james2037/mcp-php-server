<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('array-tool', 'Tests array parameters')]
class ArrayParamTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('numbers', type: 'array', description: 'List of numbers')]
        #[ParameterAttribute('enabled', type: 'boolean', description: 'Whether processing is enabled')]
        array $arguments
    ): array {
        if (!$arguments['enabled']) {
            return [$this->createTextContent('Processing disabled')];
        }
        $sum = array_sum($arguments['numbers']);
        return [$this->createTextContent("Sum: $sum")];
    }
}
