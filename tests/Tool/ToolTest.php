<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;
use MCP\Server\Tool\Content\ContentItemInterface;
use MCP\Server\Tests\Tool\Fixture\LifecycleTestTool;
use MCP\Server\Tests\Tool\Fixture\ValidationTestTool;

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

    public function testInitializeMethodSetsFlagAndLogs(): void
    {
        $tool = new LifecycleTestTool();
        // Assert initial state (before explicit initialize call)
        $this->assertFalse($tool->initialized, "Tool should not be initialized before initialize() is called.");
        $initialLog = $tool->getLog();
        $this->assertNotContains("initialize called", $initialLog, "Log should not contain 'initialize called' before execution.");
        $this->assertContains("Before parent constructor", $initialLog);
        $this->assertContains("After parent constructor", $initialLog);

        // Call initialize
        $tool->initialize();

        $this->assertTrue($tool->initialized, "Tool initialize flag should be true after calling initialize().");
        $finalLog = $tool->getLog();
        $this->assertContains("initialize called", $finalLog, "Log should contain 'initialize called' after execution.");

        // Check full log order now
        $expectedLog = [
            "Before parent constructor",
            "After parent constructor",
            "initialize called"
        ];
        $this->assertEquals($expectedLog, $finalLog);
    }

    public function testShutdownMethodSetsFlag(): void
    {
        $tool = new LifecycleTestTool();
        // Reset log to make assertions cleaner for shutdown only
        $tool->clearLog();

        $tool->shutdown();
        $this->assertTrue($tool->shutdown, "Tool shutdown flag should be true after calling shutdown().");
        $this->assertContains("shutdown called", $tool->getLog(), "Log should contain 'shutdown called'.");
        $this->assertCount(1, $tool->getLog()); // Ensure only shutdown was logged now
        $this->assertEquals("shutdown called", $tool->getLog()[0]);
    }

    /**
     * Helper method to set the private 'parameters' property on a Tool instance using reflection.
     *
     * @param Tool $tool The tool instance.
     * @param array<string, array{type: string, description?: string|null, required?: bool}> $paramsConfig
     *        An array where keys are parameter names and values are arrays defining the parameter.
     *        Example: ['paramName' => ['type' => 'string', 'required' => true]]
     */
    private function setToolParameters(Tool $tool, array $paramsConfig): void
    {
        $parameters = [];
        foreach ($paramsConfig as $name => $config) {
            $parameters[$name] = new \MCP\Server\Tool\Attribute\Parameter(
                name: $name,
                type: $config['type'],
                description: $config['description'] ?? null,
                required: $config['required'] ?? true
            );
        }

        // Get reflection of the parent class (Tool) to access its private properties
        $reflection = new \ReflectionClass(Tool::class);
        $parametersProperty = $reflection->getProperty('parameters');
        $parametersProperty->setAccessible(true); // Allow modification of private property
        $parametersProperty->setValue($tool, $parameters); // Set value on the $tool instance
    }

    // Tests for 'integer' type validation
    public function testValidateArgumentsIntegerTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
        ]);
        $result = $tool->execute(['pInt' => 123, 'pObj' => new \stdClass(), 'pAny' => 'hello']);
        $this->assertStringContainsString("pInt type: integer", $result[0]['text']);
    }

    public function testValidateArgumentsIntegerTypeIncorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid type for argument pInt: expected integer, got string");
        $tool->execute(['pInt' => "not-an-integer", 'pObj' => new \stdClass(), 'pAny' => 'hello']);
    }

    public function testValidateArgumentsIntegerTypeMissingRequired(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer', 'required' => true],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required argument: pInt");
        $tool->execute(['pObj' => new \stdClass(), 'pAny' => 'hello']);
    }

    public function testValidateArgumentsOptionalIntegerTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
            'pOptInt' => ['type' => 'integer', 'required' => false],
        ]);
        // pOptInt is provided
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a', 'pOptInt' => 456]);
        $this->assertStringContainsString("pOptInt type: integer", $result[0]['text']);

        // pOptInt is omitted (which is valid)
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a']);
        $this->assertStringNotContainsString("pOptInt type", $result[0]['text']);
    }

    // Tests for 'object' type validation
    public function testValidateArgumentsObjectTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
        ]);
        $result = $tool->execute(['pInt' => 123, 'pObj' => new \stdClass(), 'pAny' => 'hello']);
        $this->assertStringContainsString("pObj type: object", $result[0]['text']);
    }

    public function testValidateArgumentsObjectTypeIncorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid type for argument pObj: expected object, got string");
        $tool->execute(['pInt' => 123, 'pObj' => "not-an-object", 'pAny' => 'hello']);
    }

    public function testValidateArgumentsObjectTypeMissingRequired(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object', 'required' => true],
            'pAny' => ['type' => 'any'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required argument: pObj");
        $tool->execute(['pInt' => 123, 'pAny' => 'hello']);
    }

    public function testValidateArgumentsOptionalObjectTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
            'pOptObj' => ['type' => 'object', 'required' => false],
        ]);
        // pOptObj is provided
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a', 'pOptObj' => new \stdClass()]);
        $this->assertStringContainsString("pOptObj type: object", $result[0]['text']);

        // pOptObj is omitted
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a']);
        $this->assertStringNotContainsString("pOptObj type", $result[0]['text']);
    }

    // Tests for 'any' type validation
    public function testValidateArgumentsAnyTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'], // 'required' defaults to true
        ]);

        // Test null value explicitly for 'any' type
        $resultNull = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => null]);
        $this->assertStringContainsString("pAny type: NULL", $resultNull[0]['text'], "Failed for 'any' type with null value.");

        $testValues = [
            'string_val' => "hello",
            'int_val' => 123,
            'bool_val' => true,
            'array_val' => ['a', 'b'],
            'object_val' => new \stdClass(),
            'double_val' => 1.23,
        ];

        foreach ($testValues as $key => $value) {
            $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => $value]);
            $this->assertStringContainsString("pAny type: " . gettype($value), $result[0]['text'], "Failed for 'any' type with key: " . $key . " value type: " . gettype($value));
        }
    }

    public function testValidateArgumentsAnyTypeMissingRequired(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any', 'required' => true],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required argument: pAny");
        $tool->execute(['pInt' => 123, 'pObj' => new \stdClass()]);
    }

    public function testValidateArgumentsOptionalAnyTypeCorrect(): void
    {
        $tool = new ValidationTestTool();
        $this->setToolParameters($tool, [
            'pInt' => ['type' => 'integer'],
            'pObj' => ['type' => 'object'],
            'pAny' => ['type' => 'any'],
            'pOptAny' => ['type' => 'any', 'required' => false],
        ]);
        // pOptAny is provided with a string
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a', 'pOptAny' => 'optional string']);
        $this->assertStringContainsString("pOptAny type: string", $result[0]['text']);

        // pOptAny is omitted
        $result = $tool->execute(['pInt' => 1, 'pObj' => new \stdClass(), 'pAny' => 'a']);
        $this->assertStringNotContainsString("pOptAny type", $result[0]['text']);
    }
}
