<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;

#[ToolAttribute('greeter', 'A friendly greeter')]
class OptionalParamTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet')]
        #[ParameterAttribute('title', type: 'string', description: 'Optional title', required: false)]
        array $arguments
    ): array {
        $title = $arguments['title'] ?? 'friend';
        return [$this->createTextContent("Hello {$title} {$arguments['name']}")];
    }
}

#[ToolAttribute('array-tool', 'Tests array parameters')]
class ArrayParamTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('numbers', type: 'array', description: 'List of numbers')]
        #[ParameterAttribute('enabled', type: 'boolean', description: 'Whether processing is enabled')]
        array $arguments
    ): array {
        if (!$arguments['enabled']) {
            return [$this->createTextContent('Processing disabled')];
        }
        $sum = array_sum($arguments['numbers']);
        return [$this->createTextContent("Sum: $sum")];
    }
}

#[ToolAttribute('multi-output', 'Tests multiple output types')]
class MultiOutputTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('format', type: 'string', description: 'Output format (text/image)')]
        array $arguments
    ): array {
        if ($arguments['format'] === 'text') {
            return [$this->createTextContent('Hello world')];
        }
        return [$this->createImageContent('fake-image-data', 'image/png')];
    }
}

class ExtendedToolTest extends TestCase
{
    public function testOptionalParameters(): void
    {
        $tool = new OptionalParamTool();

        // Test schema includes optional parameter
        $schema = $tool->getInputSchema();
        $this->assertTrue(isset($schema['properties']->title));
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
        $this->assertEquals('array', $schema['properties']->numbers->type);
        $this->assertEquals('boolean', $schema['properties']->enabled->type);

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

        $this->assertEquals('Name to greet', $schema['properties']->name->description);
        $this->assertEquals('Optional title', $schema['properties']->title->description);
    }
}
