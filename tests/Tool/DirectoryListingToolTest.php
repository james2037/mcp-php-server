<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Tool\DirectoryListingTool;
use MCP\Server\Tool\Content\TextContent;
use PHPUnit\Framework\TestCase;

class DirectoryListingToolTest extends TestCase
{
    private ToolsCapability $toolsCapability;
    private DirectoryListingTool $directoryListingTool;

    protected function setUp(): void
    {
        $this->toolsCapability = new ToolsCapability();
        $this->directoryListingTool = new DirectoryListingTool();
        $this->toolsCapability->addTool($this->directoryListingTool);
    }

    public function testDirectoryListingToolListsExistingDirectory(): void
    {
        // Create a temporary directory with some files
        $testDirPath = sys_get_temp_dir() . '/test_dir_' . uniqid();
        mkdir($testDirPath);
        file_put_contents($testDirPath . '/file1.txt', "Hello");
        file_put_contents($testDirPath . '/file2.txt', "World");
        mkdir($testDirPath . '/subdir');

        $result = $this->directoryListingTool->execute(['directory_path' => $testDirPath]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]); // Expect an array after toArray()
        $this->assertArrayHasKey('type', $result[0]);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertArrayHasKey('text', $result[0]);

        $expectedOutput = "file1.txt\nfile2.txt\nsubdir";
        $this->assertEquals($expectedOutput, $result[0]['text']);

        // Clean up
        unlink($testDirPath . '/file1.txt');
        unlink($testDirPath . '/file2.txt');
        rmdir($testDirPath . '/subdir');
        rmdir($testDirPath);
    }

    public function testDirectoryListingToolThrowsExceptionForNonExistentDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Directory not found at path: /non/existent/directory");

        $this->directoryListingTool->execute(['directory_path' => '/non/existent/directory']);
    }

    public function testDirectoryListingToolThrowsExceptionForNonStringPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid type for argument directory_path: expected string, got integer");

        $this->directoryListingTool->execute(['directory_path' => 123]);
    }

    public function testDirectoryListingToolThrowsExceptionForUnreadableDirectory(): void
    {
        // Create a temporary directory and make it unreadable
        $testDirPath = sys_get_temp_dir() . '/test_dir_' . uniqid();
        mkdir($testDirPath, 0000); // Create with no permissions

        $this->expectException(\RuntimeException::class);
        // The exact message can vary slightly based on OS or PHP version,
        // so we check for a partial message.
        $this->expectExceptionMessageMatches("/Directory is not readable: .*|Unable to read directory contents from path: .*/");


        try {
            $this->directoryListingTool->execute(['directory_path' => $testDirPath]);
        } finally {
            // Attempt to make it writable again so rmdir can succeed
            chmod($testDirPath, 0755);
            rmdir($testDirPath);
        }
    }

    public function testDirectoryListingToolReturnsEmptyForEmptyDirectory(): void
    {
        $testDirPath = sys_get_temp_dir() . '/empty_dir_' . uniqid();
        mkdir($testDirPath);

        $result = $this->directoryListingTool->execute(['directory_path' => $testDirPath]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]);
        $this->assertArrayHasKey('type', $result[0]);
        $this->assertEquals('text', $result[0]['type']);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertEquals("", $result[0]['text']);

        rmdir($testDirPath);
    }
}
