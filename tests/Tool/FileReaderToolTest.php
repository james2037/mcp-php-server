<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Tool\FileReaderTool;
use MCP\Server\Tool\Content\TextContent;
use PHPUnit\Framework\TestCase;

class FileReaderToolTest extends TestCase
{
    private $toolsCapability;
    private $fileReaderTool;

    protected function setUp(): void
    {
        $this->toolsCapability = new ToolsCapability();
        $this->fileReaderTool = new FileReaderTool();
        $this->toolsCapability->addTool($this->fileReaderTool);
    }

    public function testFileReaderToolReadsExistingFile()
    {
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_file_');
        file_put_contents($testFilePath, "Hello, world!");

        $result = $this->fileReaderTool->execute(['filepath' => $testFilePath]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]); // Expect an array after toArray()
        $this->assertArrayHasKey('type', $result[0]);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertEquals("Hello, world!", $result[0]['text']);

        unlink($testFilePath);
    }

    public function testFileReaderToolThrowsExceptionForNonExistentFile()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("File not found at path: /non/existent/file.txt");

        $this->fileReaderTool->execute(['filepath' => '/non/existent/file.txt']);
    }

    public function testFileReaderToolThrowsExceptionForUnreadableFile()
    {
        // Create a temporary file and make it unreadable
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_file_');
        // On Linux/macOS, chmod 000 makes it unreadable.
        // This might behave differently on Windows or other OS.
        chmod($testFilePath, 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unable to read file content from path: " . $testFilePath);

        try {
            $this->fileReaderTool->execute(['filepath' => $testFilePath]);
        } finally {
            // Attempt to make it writable again so unlink can succeed
            chmod($testFilePath, 0644);
            unlink($testFilePath);
        }
    }
}
