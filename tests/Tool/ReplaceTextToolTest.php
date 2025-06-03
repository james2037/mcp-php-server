<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Resource\TextResourceContents;
use MCP\Server\Tool\Content\TextContent; // Added use statement
use MCP\Server\Tool\ReplaceTextTool;
use PHPUnit\Framework\TestCase;

class ReplaceTextToolTest extends TestCase
{
    public function testReplaceText(): void
    {
        $resourceRegistry = new ResourceRegistry();
        $tool = new ReplaceTextTool($resourceRegistry);

        $filePath = tempnam(sys_get_temp_dir(), 'replace_text_test');
        if ($filePath === false) {
            $this->fail('Failed to create temporary file.');
        }
        file_put_contents($filePath, 'Hello world, hello world!'); // Keep for now to simulate existing file

        $mockResource = $this->createMock(Resource::class);

        $initialFileContents = 'Hello world, hello world!';
        $expectedModifiedContents = 'Hello Jules, hello Jules!'; // For the error message

        $textResourceContents = new TextResourceContents($filePath, $initialFileContents);
        $mockResource->method('read')->willReturn($textResourceContents);

        // Resource::write does not exist, so no expectation for it.

        $mockResource->method('getUri')->willReturn($filePath);

        // Make ResourceRegistry return our mock resource
        // We can't directly set getResources() easily without changing ResourceRegistry
        // or using a more complex mock for ResourceRegistry itself.
        // So, we register the mock. The Tool will then find it via getResources()[$filePath].
        $resourceRegistry->register($mockResource);

        $contentItemArray = $tool($filePath, 'world', 'Jules');
        $this->assertIsArray($contentItemArray);
        $this->assertCount(1, $contentItemArray);
        $result = $contentItemArray[0];
        $this->assertInstanceOf(TextContent::class, $result);

        // Expect the error message because Resource is not writable
        $expectedMessage = "Error: Resource '{$filePath}' is not writable. Content was not saved. Modified content: {$expectedModifiedContents}";
        $this->assertSame($expectedMessage, $result->toArray()['text']);

        unlink($filePath);
    }
}
