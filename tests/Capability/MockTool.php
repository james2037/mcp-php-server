<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;

#[ToolAnnotations(title: 'Mock Test Tool', readOnlyHint: true)]
#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('data', type: 'string', description: 'Input data')]
        array $arguments
    ): array {
        return [$this->createTextContent('Result: ' . $arguments['data'])];
    }

    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allArguments = []): array
    {
        if ($argumentName === 'data') {
            $allValues = ['apple', 'apricot', 'banana', 'blueberry'];
            $filteredValues = array_filter($allValues, fn($v) => str_starts_with($v, (string)$currentValue));
            return ['values' => array_values($filteredValues), 'total' => count($filteredValues), 'hasMore' => false];
        }
        return parent::getCompletionSuggestions($argumentName, $currentValue, $allArguments);
    }
}
