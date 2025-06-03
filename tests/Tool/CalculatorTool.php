<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute('calculator', 'A calculator tool')]
class CalculatorTool extends Tool
{
    /**
     * Performs addition or subtraction on two numbers.
     * @param string $operation Operation to perform ('add' or 'subtract').
     * @param float $a First number.
     * @param float $b Second number.
     * @return TextContent[]
     * @throws \InvalidArgumentException If the operation is invalid.
     */
    protected function executeTool(
        #[ParameterAttribute('operation', type: 'string', description: 'Operation to perform (add/subtract)', required: true)]
        string $operation,
        #[ParameterAttribute('a', type: 'number', description: 'First number', required: true)]
        float $a,
        #[ParameterAttribute('b', type: 'number', description: 'Second number', required: true)]
        float $b
    ): array {
        $result = match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            default => throw new \InvalidArgumentException('Invalid operation')
        };

        return [$this->createTextContent((string)$result)];
    }

    protected function doExecute(array $arguments): array
    {
        // Base validateArguments ensures presence and correct types (string, number converted to float).
        // Note: 'number' type from Parameter attribute usually maps to float in PHP.
        return $this->executeTool(
            $arguments['operation'],
            (float)($arguments['a'] ?? 0.0), // Cast, though validateArguments should provide float/int
            (float)($arguments['b'] ?? 0.0)  // Cast
        );
    }
}
