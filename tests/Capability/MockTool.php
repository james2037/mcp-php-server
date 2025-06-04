<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAnnotations(title: 'Mock Test Tool', readOnlyHint: true)]
#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    /**
     * @return ContentItemInterface
     */
    protected function doExecute(
        #[ParameterAttribute('data', type: 'string', description: 'Input data')]
        array $arguments
    ): \MCP\Server\Tool\Content\ContentItemInterface {
        return $this->text('Result: ' . $arguments['data']);
    }

    /**
     * @param array<string, mixed> $allArguments
     * @return array{values: string[], total: int, hasMore: bool}
     */
    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allArguments = []): array
    {
        if ($argumentName === 'data') {
            $allValues = ['apple', 'apricot', 'banana', 'blueberry'];
            $filteredValues = array_filter($allValues, fn($v) => str_starts_with($v, (string)$currentValue));
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
