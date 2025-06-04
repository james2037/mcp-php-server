<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\ToolRegistry;
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;

// MockTool and OtherMockTool are now in separate files.

class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    public function testRegister(): void
    {
        $tool = new MockTool();
        $this->registry->register($tool);

        $tools = $this->registry->getTools();
        $this->assertCount(1, $tools);
        $this->assertArrayHasKey('test', $tools);
        $this->assertSame($tool, $tools['test']);
    }

    public function testRegisterMultipleTools(): void
    {
        $tool1 = new MockTool();
        $tool2 = new OtherMockTool();

        $this->registry->register($tool1);
        $this->registry->register($tool2);

        $tools = $this->registry->getTools();
        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('test', $tools);
        $this->assertArrayHasKey('other', $tools);
    }

    public function testRegisterOverwrite(): void
    {
        $tool1 = new MockTool();
        $tool2 = new MockTool();  // Same name as tool1

        $this->registry->register($tool1);
        $this->registry->register($tool2);

        $tools = $this->registry->getTools();
        $this->assertCount(1, $tools);
        $this->assertSame($tool2, $tools['test']);
    }

    public function testDiscoverTools(): void
    {
        $tempDir = sys_get_temp_dir() . '/tool-test-' . uniqid();
        mkdir($tempDir);

        try {
            // Create a test tool file
            $toolContent = <<<PHP
            <?php
            namespace MCP\\Server\\Tests\\Tool;
            
            use MCP\\Server\\Tool\\Tool;
            use MCP\\Server\\Tool\\Attribute\\Tool as ToolAttribute;
            
            #[ToolAttribute('discovered', 'Discovered Tool')]
            class DiscoveredTool extends Tool {
                protected function doExecute(array \$arguments): array {
                    return \$this->text('discovered');
                }
            }
            PHP;

            file_put_contents($tempDir . '/DiscoveredTool.php', $toolContent);

            $this->registry->discover($tempDir);

            $tools = $this->registry->getTools();
            $this->assertCount(1, $tools);
            $this->assertArrayHasKey('discovered', $tools);
        } finally {
            // Cleanup
            unlink($tempDir . '/DiscoveredTool.php');
            rmdir($tempDir);
        }
    }

    public function testDiscoverToolsWithInvalidDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->registry->discover('/nonexistent/directory');
    }

    public function testDiscoverToolsWithInvalidFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/tool-test-' . uniqid();
        mkdir($tempDir);

        try {
            // Create a file that's valid PHP but not a valid tool
            $invalidTool = <<<PHP
            <?php
            namespace MCP\\Server\\Tests\\Tool;
            
            class NotATool {
                public function someMethod() {
                    return true;
                }
            }
            PHP;

            file_put_contents($tempDir . '/InvalidTool.php', $invalidTool);

            // Should not throw but should ignore the invalid tool
            $this->registry->discover($tempDir);

            $tools = $this->registry->getTools();
            $this->assertCount(0, $tools);
        } finally {
            // Cleanup
            unlink($tempDir . '/InvalidTool.php');
            rmdir($tempDir);
        }
    }

    public function testDiscoverSkipsProblematicFiles(): void
    {
        // Directory containing files that should not be registered as tools
        $testFilesDir = __DIR__ . '/../Registry/DiscoveryTestFiles';

        // Attempt to discover tools in the directory with problematic files
        $this->registry->discover($testFilesDir);

        // Assert that no tools were registered from these files
        $tools = $this->registry->getTools();
        $this->assertCount(0, $tools, "Registry should be empty after discovering problematic files.");
    }

    public function testDiscoveryWithVariousFileTypes(): void
    {
        $testFilesDir = __DIR__ . '/../Registry/DiscoveryTestFiles';
        // Ensure our newly added files are there.
        $this->assertFileExists($testFilesDir . '/TestInterface.php');
        $this->assertFileExists($testFilesDir . '/TestEnum.php');
        $this->assertFileExists($testFilesDir . '/SyntaxErrorFile.php');
        $this->assertFileExists($testFilesDir . '/EmptyFile.php');

        // Test with a registry instance that uses a custom getClassFromFile for direct testing.
        // This is a bit complex as getClassFromFile is private.
        // So, we'll rely on the indirect effect: these files should not lead to registered tools.
        // For SyntaxErrorFile.php, we need to see if discover() throws a ParseError.
        // The original Registry::discover() does not catch ParseError from include_once.

        $registry = new ToolRegistry();

        // TestInterface.php: getClassFromFile should find "MCP\Server\Tests\Registry\DiscoveryTestFiles\TestInterface"
        // but it won't be registered as it's not a concrete class and not a Tool.
        // TestEnum.php: getClassFromFile should find "MCP\Server\Tests\Registry\DiscoveryTestFiles\TestEnum"
        // but it won't be registered.

        // EmptyFile.php, NoClassDefinition.php, NonClassFile.php: getClassFromFile should return empty.

        // For SyntaxErrorFile.php, the include_once in discover() should cause a ParseError.
        // We expect the discovery to halt or skip this file.
        // If it halts, the test needs to expect that.
        // Let's test this by putting SyntaxErrorFile.php in its own directory.

        $tempDir = sys_get_temp_dir() . '/syntax-error-test-' . uniqid();
        mkdir($tempDir);
        $syntaxErrorSourcePath = $testFilesDir . '/SyntaxErrorFile.php';
        $syntaxErrorDestPath = $tempDir . '/SyntaxErrorFile.php';

        // Introduce syntax error dynamically
        $originalContent = file_get_contents($syntaxErrorSourcePath);
        if ($originalContent === false) {
            $this->fail("Failed to read SyntaxErrorFile.php for dynamic error introduction.");
        }
        $errorContent = str_replace('echo "This file will have a syntax error introduced dynamically in tests";', 'echo "This file has a syntax error" // Missing semicolon', $originalContent);
        file_put_contents($syntaxErrorDestPath, $errorContent);

        $parseErrorOccurred = false;
        try {
            $registry->discover($tempDir);
        } catch (\ParseError $e) {
            $parseErrorOccurred = true;
        } finally {
            unlink($syntaxErrorDestPath);
            rmdir($tempDir);
        }
        $this->assertTrue($parseErrorOccurred, 'A ParseError should occur for the dynamically created SyntaxErrorFile.php due to include_once.');

        // Now test the other files. They should be skipped gracefully.
        // discover() will call include_once on them, which is fine.
        // getClassFromFile() should return the name for Interface/Enum, then class_exists() fails.
        // For others, getClassFromFile() returns empty.
        $registryAfterSkips = new ToolRegistry(); // Fresh registry

        // Create a temp directory with files that should be skipped but not cause fatal errors (unlike syntax error file)
        $skipTestDir = sys_get_temp_dir() . '/skip-test-' . uniqid();
        mkdir($skipTestDir);

        $uniqueSuffix = uniqid('TestSuffix');

        $filesToProcess = [
            'TestInterface.php' => "interface TestInterface",
            'TestEnum.php' => "enum TestEnum",
            // Files that don't need content modification for uniqueness
            'EmptyFile.php' => null,
            'NoClassDefinition.php' => null,
            'NonClassFile.php' => null,
        ];

        $createdFiles = [];

        foreach ($filesToProcess as $fileName => $searchString) {
            $sourcePath = $testFilesDir . '/' . $fileName;
            $destinationPath = $skipTestDir . '/' . $fileName;
            $createdFiles[] = $destinationPath;

            if ($searchString !== null) {
                $content = file_get_contents($sourcePath);
                if ($content === false) {
                    $this->fail("Failed to read {$fileName} for dynamic content modification.");
                }
                $newContent = str_replace(
                    [$searchString, "namespace MCP\\Server\\Tests\\Registry\\DiscoveryTestFiles;"],
                    ["{$searchString}_{$uniqueSuffix}", "namespace MCP\\Server\\Tests\\Registry\\DiscoveryTestFiles\\{$uniqueSuffix};"],
                    $content
                );
                file_put_contents($destinationPath, $newContent);
            } else {
                copy($sourcePath, $destinationPath);
            }
        }

        try {
            $registryAfterSkips->discover($skipTestDir);
            $tools = $registryAfterSkips->getTools();
            $this->assertCount(0, $tools, "Registry should be empty after discovering files that are not valid, instantiable tools.");
        } finally {
            foreach ($createdFiles as $filePath) {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            rmdir($skipTestDir);
        }
    }
}
