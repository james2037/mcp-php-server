<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('calculator', 'A calculator tool')]
class CalculatorTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     */
    protected function doExecute(
        #[ParameterAttribute('operation', type: 'string', description: 'Operation to perform (add/subtract)')]
        #[ParameterAttribute('a', type: 'number', description: 'First number')]
        #[ParameterAttribute('b', type: 'number', description: 'Second number')]
        array $arguments
    ): array {
        $result = match ($arguments['operation']) {
            'add' => $arguments['a'] + $arguments['b'],
            'subtract' => $arguments['a'] - $arguments['b'],
            default => throw new \InvalidArgumentException('Invalid operation')
        };

        return [$this->text((string)$result)];
    }
}
