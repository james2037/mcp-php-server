<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;

#[ToolAttribute('test.tool', 'A test tool')]
class TestTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet')]
        array $arguments
    ): array {
        return [['type' => 'text', 'text' => 'Hello ' . $arguments['name']]];
    }
}

#[ToolAttribute('calculator', 'A calculator tool')]
class CalculatorTool extends Tool
{
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

        return [['type' => 'text', 'text' => (string)$result]];
    }
}

class ToolTest extends TestCase
{
    public function testToolMetadata(): void
    {
        $tool = new TestTool();

        $this->assertEquals('test.tool', $tool->getName());
        $this->assertEquals('A test tool', $tool->getDescription());
    }

    public function testDefaultSchema(): void
    {
        $tool = new TestTool();
        $schema = $tool->getInputSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertEquals('string', $schema['properties']['name']['type']);
    }

    public function testParameterSchema(): void
    {
        $tool = new CalculatorTool();
        $schema = $tool->getInputSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);

        // Check operation parameter
        $this->assertArrayHasKey('operation', $schema['properties']);
        $this->assertEquals('string', $schema['properties']['operation']['type']);
        $this->assertEquals('Operation to perform (add/subtract)', $schema['properties']['operation']['description']);

        // Check number parameters
        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertEquals('number', $schema['properties']['a']['type']);
        $this->assertArrayHasKey('b', $schema['properties']);
        $this->assertEquals('number', $schema['properties']['b']['type']);

        // Check required fields are present (all parameters are required by default)
        $this->assertContains('operation', $schema['required']);
        $this->assertContains('a', $schema['required']);
        $this->assertContains('b', $schema['required']);
    }

    public function testValidExecution(): void
    {
        $calculator = new CalculatorTool();

        $result = $calculator->execute(
            [
            'operation' => 'add',
            'a' => 5,
            'b' => 3
            ]
        );

        $this->assertCount(1, $result);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertEquals('8', $result[0]['text']);

        $result = $calculator->execute(
            [
            'operation' => 'subtract',
            'a' => 10,
            'b' => 4
            ]
        );

        $this->assertEquals('6', $result[0]['text']);
    }

    public function testMissingRequiredArgument(): void
    {
        $calculator = new CalculatorTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required argument: operation');

        $calculator->execute(
            [
            'a' => 5,
            'b' => 3
            ]
        );
    }

    public function testInvalidArgumentType(): void
    {
        $calculator = new CalculatorTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type for argument a: expected number');

        $calculator->execute(
            [
            'operation' => 'add',
            'a' => 'not a number',
            'b' => 3
            ]
        );
    }

    public function testUnknownArgument(): void
    {
        $calculator = new CalculatorTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown argument: extra');

        $calculator->execute(
            [
            'operation' => 'add',
            'a' => 5,
            'b' => 3,
            'extra' => 'value'
            ]
        );
    }

    public function testInvalidOperation(): void
    {
        $calculator = new CalculatorTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operation');

        $calculator->execute(
            [
            'operation' => 'multiply', // Not supported
            'a' => 5,
            'b' => 3
            ]
        );
    }
}
