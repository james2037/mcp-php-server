<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\ToolRegistry;
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use PHPUnit\Framework\TestCase;

#[ToolAttribute('test', 'Test Tool')]
class MockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        return $this->text('Hello World');
    }
}

#[ToolAttribute('other', 'Other Tool')]
class OtherMockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        return $this->text('Other Result');
    }
}

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
}
