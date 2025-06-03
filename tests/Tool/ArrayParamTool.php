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
        $numbersValue = $arguments['numbers'] ?? null;
        if (!is_array($numbersValue)) {
            // This path indicates an issue with argument parsing or tool definition,
            // as 'numbers' is declared as type: 'array'.
            return [$this->createTextContent("Error: 'numbers' parameter must be an array.")];
        }
        $sum = array_sum($numbersValue);
        return [$this->createTextContent("Sum: $sum")];
    }
}
