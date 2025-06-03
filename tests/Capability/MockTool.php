<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAnnotations(title: 'Mock Test Tool', readOnlyHint: true)]
#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    /**
     * Executes the mock tool logic.
     * @param string $data Input data.
     * @return TextContent[]
     */
    protected function executeTool(
        #[ParameterAttribute('data', type: 'string', description: 'Input data', required: true)]
        string $data
    ): array {
        return [$this->createTextContent('Result: ' . $data)];
    }

    protected function doExecute(array $arguments): array
    {
        // Base validateArguments ensures 'data' is present and is a string.
        return $this->executeTool($arguments['data']);
    }

    /**
     * @param array<string, mixed> $allArguments
     * @return array{values: string[], total: int, hasMore: bool}
     */
    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allArguments = []): array
    {
        if ($argumentName === 'data') {
            $allValues = ['apple', 'apricot', 'banana', 'blueberry'];
            $prefixToSearch = is_scalar($currentValue) ? (string)$currentValue : '';
            $filteredValues = array_filter($allValues, fn($v) => str_starts_with($v, $prefixToSearch));
            return ['values' => array_values($filteredValues), 'total' => count($filteredValues), 'hasMore' => false];
        }
        $suggestions = parent::getCompletionSuggestions($argumentName, $currentValue, $allArguments);

        // Ensure 'total' and 'hasMore' are set, as our PHPDoc guarantees them.
        // The parent PHPDoc indicates they are optional.
        if (!isset($suggestions['total'])) {
            $suggestions['total'] = count($suggestions['values']); // Sensible default
        }
        if (!isset($suggestions['hasMore'])) {
            $suggestions['hasMore'] = false; // Sensible default
        }

        return $suggestions;
    }
}
