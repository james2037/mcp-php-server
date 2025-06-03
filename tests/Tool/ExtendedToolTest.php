<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;

// OptionalParamTool, ArrayParamTool, and MultiOutputTool are now in separate files.

class ExtendedToolTest extends TestCase
{
    public function testOptionalParameters(): void
    {
        $tool = new OptionalParamTool();

        // Test schema includes optional parameter
        $schema = $tool->getInputSchema();
        self::assertArrayHasKey('properties', $schema);
        self::assertIsObject($schema['properties']);
        self::assertObjectHasProperty('title', $schema['properties']); // Check property existence
        $this->assertTrue(isset($schema['properties']->title)); // Keep original logic if it's about isset specifically

        self::assertArrayHasKey('required', $schema);
        self::assertIsArray($schema['required']);
        $this->assertContains('name', $schema['required']);
        $this->assertNotContains('title', $schema['required']);

        // Test with only required parameter
        $result = $tool->execute(
            [
            'name' => 'Alice'
            ]
        );
        $this->assertEquals('Hello friend Alice', $result[0]['text']);

        // Test with optional parameter
        $result = $tool->execute(
            [
            'name' => 'Alice',
            'title' => 'Dr.'
            ]
        );
        $this->assertEquals('Hello Dr. Alice', $result[0]['text']);
    }

    public function testArrayParameters(): void
    {
        $tool = new ArrayParamTool();

        // Test schema types
        $schema = $tool->getInputSchema();
        self::assertArrayHasKey('properties', $schema);
        self::assertIsObject($schema['properties']);

        self::assertObjectHasProperty('numbers', $schema['properties']);
        self::assertIsArray($schema['properties']->numbers); // It's an array from jsonSerialize()
        $this->assertEquals('array', $schema['properties']->numbers['type']); // Array access

        self::assertObjectHasProperty('enabled', $schema['properties']);
        self::assertIsArray($schema['properties']->enabled); // It's an array
        $this->assertEquals('boolean', $schema['properties']->enabled['type']); // Array access

        // Test array processing
        $result = $tool->execute(
            [
            'numbers' => [1, 2, 3, 4, 5],
            'enabled' => true
            ]
        );
        $this->assertEquals('Sum: 15', $result[0]['text']);

        // Test boolean control flow
        $result = $tool->execute(
            [
            'numbers' => [1, 2, 3],
            'enabled' => false
            ]
        );
        $this->assertEquals('Processing disabled', $result[0]['text']);
    }

    public function testMultipleOutputTypes(): void
    {
        $tool = new MultiOutputTool();

        // Test text output
        $result = $tool->execute(['format' => 'text']);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertEquals('Hello world', $result[0]['text']);

        // Test image output
        $result = $tool->execute(['format' => 'image']);
        $this->assertEquals('image', $result[0]['type']);
        $this->assertEquals('image/png', $result[0]['mimeType']);
        $this->assertEquals(base64_encode('fake-image-data'), $result[0]['data']);
    }

    public function testInvalidArrayType(): void
    {
        $tool = new ArrayParamTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type for argument numbers: expected array');

        $tool->execute(
            [
            'numbers' => 'not an array',
            'enabled' => true
            ]
        );
    }

    public function testInvalidBooleanType(): void
    {
        $tool = new ArrayParamTool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type for argument enabled: expected boolean');

        $tool->execute(
            [
            'numbers' => [1, 2, 3],
            'enabled' => 'true'  // string instead of boolean
            ]
        );
    }

    public function testDescriptionsInSchema(): void
    {
        $tool = new OptionalParamTool();
        $schema = $tool->getInputSchema();

        self::assertArrayHasKey('properties', $schema);
        self::assertIsObject($schema['properties']);

        self::assertObjectHasProperty('name', $schema['properties']);
        self::assertIsArray($schema['properties']->name); // It's an array
        $this->assertEquals('Name to greet', $schema['properties']->name['description']); // Array access

        self::assertObjectHasProperty('title', $schema['properties']);
        self::assertIsArray($schema['properties']->title); // It's an array
        $this->assertEquals('Optional title', $schema['properties']->title['description']); // Array access
    }
}
