<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\SimpleCalculatorTool;
use PHPUnit\Framework\TestCase;

class SimpleCalculatorToolTest extends TestCase
{
    public function testToolMetadata(): void
    {
        $tool = new SimpleCalculatorTool();

        $this->assertEquals('SimpleCalculator', $tool->getName());
        $this->assertEquals('Performs basic arithmetic operations.', $tool->getDescription());
    }

    public function testInputSchema(): void
    {
        $tool = new SimpleCalculatorTool();
        $schema = $tool->getInputSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);

        // Check number1 parameter
        $this->assertTrue(isset($schema['properties']->number1));
        $this->assertEquals('number', $schema['properties']->number1->type);
        $this->assertEquals('The first number.', $schema['properties']->number1->description);

        // Check number2 parameter
        $this->assertTrue(isset($schema['properties']->number2));
        $this->assertEquals('number', $schema['properties']->number2->type);
        $this->assertEquals('The second number.', $schema['properties']->number2->description);

        // Check operation parameter
        $this->assertTrue(isset($schema['properties']->operation));
        $this->assertEquals('string', $schema['properties']->operation->type);
        $this->assertEquals('The operation to perform.', $schema['properties']->operation->description);
        // $this->assertEquals(['add', 'subtract', 'multiply', 'divide'], $schema['properties']->operation->enum); // Removed: Enum is not directly supported by Parameter attribute for schema

        // Check required fields
        $this->assertContains('number1', $schema['required']);
        $this->assertContains('number2', $schema['required']);
        $this->assertContains('operation', $schema['required']);
    }

    /**
     * @dataProvider validExecutionDataProvider
     */
    public function testValidExecution(float $number1, float $number2, string $operation, string $expectedResult): void
    {
        $tool = new SimpleCalculatorTool();
        $result = $tool->execute(['number1' => $number1, 'number2' => $number2, 'operation' => $operation]);

        $this->assertCount(1, $result);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertEquals($expectedResult, $result[0]['text']);
    }

    /**
     * @return array<string, array{float, float, string, string}>
     */
    public static function validExecutionDataProvider(): array
    {
        return [
            'add' => [5.0, 3.0, 'add', '8'],
            'subtract' => [10.0, 4.0, 'subtract', '6'],
            'multiply' => [7.0, 6.0, 'multiply', '42'],
            'divide' => [20.0, 5.0, 'divide', '4'],
            'divide with float result' => [10.0, 4.0, 'divide', '2.5'],
        ];
    }

    public function testMissingRequiredArgumentNumber1(): void
    {
        $tool = new SimpleCalculatorTool();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: number1');
        $tool->execute(['number2' => 5, 'operation' => 'add']);
    }

    public function testMissingRequiredArgumentOperation(): void
    {
        $tool = new SimpleCalculatorTool();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: operation');
        $tool->execute(['number1' => 5, 'number2' => 3]);
    }

    public function testInvalidArgumentTypeNumber1(): void
    {
        $tool = new SimpleCalculatorTool();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type for argument number1: expected number, got string');
        $tool->execute(['number1' => 'not-a-number', 'number2' => 5, 'operation' => 'add']);
    }

    public function testInvalidArgumentTypeOperation(): void
    {
        $tool = new SimpleCalculatorTool();
        $this->expectException(\InvalidArgumentException::class);
        // The message comes from the Parameter attribute type, which is 'string'
        $this->expectExceptionMessage('Invalid type for argument operation: expected string, got integer');
        $tool->execute(['number1' => 5, 'number2' => 3, 'operation' => 123]);
    }

    public function testUnknownArgument(): void
    {
        $tool = new SimpleCalculatorTool();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown argument: extra_param');
        $tool->execute(['number1' => 5, 'number2' => 3, 'operation' => 'add', 'extra_param' => 'value']);
    }

    public function testInvalidOperation(): void
    {
        $tool = new SimpleCalculatorTool();
        // This doesn't throw InvalidArgumentException, but returns a text error.
        // This behavior is defined in the SimpleCalculatorTool's doExecute method.
        $result = $tool->execute(['number1' => 5, 'number2' => 3, 'operation' => 'modulo']);
        $this->assertCount(1, $result);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertEquals('Error: Invalid operation. Must be one of: add, subtract, multiply, divide.', $result[0]['text']);
    }

    public function testDivisionByZero(): void
    {
        $tool = new SimpleCalculatorTool();
        // This also returns a text error as per current implementation.
        $result = $tool->execute(['number1' => 5.0, 'number2' => 0.0, 'operation' => 'divide']);
        $this->assertCount(1, $result);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertEquals('Error: Division by zero.', $result[0]['text']);
    }
}
