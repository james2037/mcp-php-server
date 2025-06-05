<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;
use MCP\Server\Tool\Content\TextContent;

#[ToolAttribute(name: 'SimpleCalculator', description: 'Performs basic arithmetic operations.')]
class SimpleCalculatorTool extends Tool
{
    protected function doExecute(
        #[Parameter(name: 'number1', description: 'The first number.', type: 'number', required: true)]
        #[Parameter(name: 'number2', description: 'The second number.', type: 'number', required: true)]
        #[Parameter(name: 'operation', description: 'The operation to perform.', type: 'string', required: true)]
        array $arguments
    ): ContentItemInterface {
        $number1 = (float)$arguments['number1'];
        $number2 = (float)$arguments['number2'];
        $operation = (string)$arguments['operation'];

        $result = 0;

        switch ($operation) {
            case 'add':
                $result = $number1 + $number2;
                break;
            case 'subtract':
                $result = $number1 - $number2;
                break;
            case 'multiply':
                $result = $number1 * $number2;
                break;
            case 'divide':
                if ($number2 == 0.0) {
                    return $this->text('Error: Division by zero.');
                }
                $result = $number1 / $number2;
                break;
            default:
                return $this->text('Error: Invalid operation. Must be one of: add, subtract, multiply, divide.');
        }

        return $this->text((string)$result);
    }
}
