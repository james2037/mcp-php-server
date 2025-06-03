<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Tool\FileReaderTool;
use MCP\Server\Tool\Content\TextContent;
use PHPUnit\Framework\TestCase;

class FileReaderToolTest extends TestCase
{
    private ToolsCapability $toolsCapability;
    private FileReaderTool $fileReaderTool;

    protected function setUp(): void
    {
        $this->toolsCapability = new ToolsCapability();
        $this->fileReaderTool = new FileReaderTool();
        $this->toolsCapability->addTool($this->fileReaderTool);
    }

    public function testFileReaderToolReadsExistingFile(): void
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

    public function testFileReaderToolThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("File not found at path: /non/existent/file.txt");

        $this->fileReaderTool->execute(['filepath' => '/non/existent/file.txt']);
    }

    public function testFileReaderToolThrowsExceptionForUnreadableFile(): void
    {
        // Create a temporary file and make it unreadable
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_file_');
        // On Linux/macOS, chmod 000 makes it unreadable.
        // This might behave differently on Windows or other OS.
        chmod($testFilePath, 0000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("File is not readable: " . $testFilePath);

        try {
            $this->fileReaderTool->execute(['filepath' => $testFilePath]);
        } finally {
            // Attempt to make it writable again so unlink can succeed
            chmod($testFilePath, 0644);
            unlink($testFilePath);
        }
    }
}
