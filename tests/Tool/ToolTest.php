<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;
use MCP\Server\Tool\Content\ContentItemInterface;

// TestTool and CalculatorTool are now in separate files.

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
        $this->assertIsString($json, "json_encode should return a string for a valid schema.");
        // If $json is false, the assertIsString would have failed.
        // So, $json is definitely a string here for json_decode.
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
     * @param mixed $data
     * @param array<string, mixed> $schema
     */
    private function validateAgainstSchema(mixed $data, array $schema): bool
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
                                && ((is_array($data) && !array_key_exists($prop, $data)) || (is_object($data) && !property_exists($data, $prop)))
                            ) {
                                return false;
                            }
                            if ((is_array($data) && array_key_exists($prop, $data)) || (is_object($data) && property_exists($data, $prop))) {
                                // Accessing $data[$prop] is fine for both array and object (if property exists)
                                // For objects, this relies on PHP converting object property access to array-like access for ArrayAccess or stdClass.
                                // However, to be more explicit and safe, especially if $data could be a specific object type
                                // not implementing ArrayAccess, we should differentiate access.
                                // $value = is_array($data) ? $data[$prop] : $data->$prop;
                                // But given $data is 'mixed' and passed around, and json_decode($assoc=true) makes arrays,
                                // let's assume for now $data[$prop] works for the objects it might be (like stdClass).
                                // If further errors arise, this access ($data[$prop]) might need refinement.
                                if (!$this->validateAgainstSchema(is_array($data) ? $data[$prop] : $data->$prop, $propSchema)) {
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

    public function testExecuteWithSingleTextContentReturn(): void
    {
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->text('Hello world');
            }
        };

        $result = $tool->execute([]);
        $this->assertEquals([['type' => 'text', 'text' => 'Hello world']], $result);
    }

    public function testExecuteWithSingleImageContentReturn(): void
    {
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->image('fakedata', 'image/png');
            }
        };

        $result = $tool->execute([]);
        $this->assertEquals([['type' => 'image', 'data' => base64_encode('fakedata'), 'mimeType' => 'image/png']], $result);
    }

    public function testExecuteWithSingleAudioContentReturn(): void
    {
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->audio('fakedata', 'audio/mp3');
            }
        };

        $result = $tool->execute([]);
        $this->assertEquals([['type' => 'audio', 'data' => base64_encode('fakedata'), 'mimeType' => 'audio/mp3']], $result);
    }

    public function testExecuteWithSingleEmbeddedResourceReturn(): void
    {
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): \MCP\Server\Tool\Content\ContentItemInterface
            {
                return $this->embeddedResource(['uri' => '/my/res', 'text' => 'res text']);
            }
        };

        $result = $tool->execute([]);
        $this->assertEquals([['type' => 'resource', 'resource' => ['uri' => '/my/res', 'text' => 'res text']]], $result);
    }

    public function testExecuteWithMultipleContentItemsReturn(): void
    {
        $tool = new class extends Tool {
            /**
             * @return array<\MCP\Server\Tool\Content\ContentItemInterface>
             */
            protected function doExecute(array $arguments): array
            {
                return [
                    $this->text('Hello'),
                    $this->image('fakedata', 'image/jpeg')
                ];
            }
        };

        $result = $tool->execute([]);
        $this->assertEquals(
            [['type' => 'text', 'text' => 'Hello'], ['type' => 'image', 'data' => base64_encode('fakedata'), 'mimeType' => 'image/jpeg']],
            $result
        );
    }

    public function testExecuteWithInvalidReturnFromDoExecuteNotContentItem(): void
    {
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): array|\MCP\Server\Tool\Content\ContentItemInterface
            {
                // @phpstan-ignore-next-line Deliberately returning invalid type for test.
                return 'not a content item';
            }
        };

        $this->expectException(\TypeError::class);
        // $this->expectExceptionMessage('doExecute must return an array of ContentItemInterface objects or a single ContentItemInterface object.');
        $tool->execute([]);
    }

    public function testExecuteWithInvalidReturnFromDoExecuteArrayWithInvalidItem(): void
    {
        $tool = new class extends Tool {
            /**
             * @return array<\MCP\Server\Tool\Content\ContentItemInterface>
             */
            protected function doExecute(array $arguments): array
            {
                // @phpstan-ignore-next-line Deliberately returning invalid type in array for test.
                return [$this->text("valid"), "invalid"];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All items returned by doExecute must be instances of ContentItemInterface.');
        $tool->execute([]);
    }
}
