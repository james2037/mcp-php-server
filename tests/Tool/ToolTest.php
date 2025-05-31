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
        return [$this->createTextContent('Hello ' . $arguments['name'])];
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

        return [$this->createTextContent((string)$result)];
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
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);
        $this->assertTrue(isset($schema['properties']->name));
        $this->assertEquals('string', $schema['properties']->name->type);
    }

    public function testParameterSchema(): void
    {
        $tool = new CalculatorTool();
        $schema = $tool->getInputSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);

        // Check operation parameter
        $this->assertTrue(isset($schema['properties']->operation));
        $this->assertEquals('string', $schema['properties']->operation->type);
        $this->assertEquals(
            'Operation to perform (add/subtract)',
            $schema['properties']->operation->description
        );

        // Check number parameters
        $this->assertTrue(isset($schema['properties']->a));
        $this->assertEquals('number', $schema['properties']->a->type);
        $this->assertTrue(isset($schema['properties']->b));
        $this->assertEquals('number', $schema['properties']->b->type);

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

    public function testSchemaEncodesAsValidJson(): void
    {
        $tool = new CalculatorTool();
        $schema = $tool->getInputSchema();

        // Test that properties is an object, not an array
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);

        // Encode and decode to check JSON structure
        $json = json_encode($schema);
        $decoded = json_decode($json, true);

        // Validate the decoded structure
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('properties', $decoded);
        $this->assertIsArray($decoded['properties']); // JSON decodes objects as associative arrays
        $this->assertArrayHasKey('operation', $decoded['properties']);
        $this->assertArrayHasKey('type', $decoded['properties']['operation']);

        // Verify no numeric keys in properties
        foreach (array_keys($decoded['properties']) as $key) {
            $this->assertIsString($key, "Property key should be string, got: " . gettype($key));
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
                $key,
                "Property key should be a valid identifier: $key"
            );
        }
    }

    public function testSchemaValidatesAgainstTypeScript(): void
    {
        $tool = new CalculatorTool();
        $schema = $tool->getInputSchema();

        // Create a JSON Schema representation of the TypeScript interface
        $tsSchema = [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'const' => 'object'],
                'properties' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'description' => ['type' => 'string']
                        ],
                        'required' => ['type']
                    ]
                ],
                'required' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ]
            ],
            'required' => ['type', 'properties']
        ];

        // Validate schema matches expected structure
        $this->assertTrue(
            $this->validateAgainstSchema($schema, $tsSchema),
            "Schema should match TypeScript interface definition"
        );
    }

    /**
     * Simple JSON Schema validator
     */
    private function validateAgainstSchema($data, array $schema): bool
    {
        if (isset($schema['type'])) {
            switch ($schema['type']) {
                case 'object':
                    if (!is_object($data) && !is_array($data)) {
                        return false;
                    }
                    if (isset($schema['properties'])) {
                        foreach ($schema['properties'] as $prop => $propSchema) {
                            if (
                                isset($schema['required'])
                                && in_array($prop, $schema['required'])
                                && !array_key_exists($prop, $data)
                            ) {
                                return false;
                            }
                            if (array_key_exists($prop, $data)) {
                                if (!$this->validateAgainstSchema($data[$prop], $propSchema)) {
                                    return false;
                                }
                            }
                        }
                    }
                    return true;
                case 'array':
                    if (!is_array($data)) {
                        return false;
                    }
                    if (isset($schema['items'])) {
                        foreach ($data as $item) {
                            if (!$this->validateAgainstSchema($item, $schema['items'])) {
                                return false;
                            }
                        }
                    }
                    return true;
                case 'string':
                    return is_string($data);
                default:
                    return true;
            }
        }
        return true;
    }
}
