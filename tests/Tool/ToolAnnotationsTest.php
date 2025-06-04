<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool;

use PHPUnit\Framework\TestCase;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tests\Tool\Fixture\ToolWithAnnotations;
use MCP\Server\Tests\Tool\Fixture\DestructiveTool;
use MCP\Server\Tests\Tool\Fixture\IdempotentTool;
use MCP\Server\Tests\Tool\Fixture\OpenWorldTool;
use MCP\Server\Tests\Tool\Fixture\ReadOnlyTool;
use MCP\Server\Tests\Tool\Fixture\EmptyAnnotationsTool;
use MCP\Server\Tests\Tool\Fixture\AllNullAnnotationsTool;
use MCP\Server\Tool\Tool; // Needed for the anonymous class test case
use MCP\Server\Tool\Content\ContentItemInterface;

// Needed for anonymous class


class ToolAnnotationsTest extends TestCase
{
    public function testInitializeMetadataWithTitle(): void
    {
        $tool = new ToolWithAnnotations();
        $annotations = $tool->getAnnotations();
        $this->assertNotNull($annotations);
        $this->assertArrayHasKey('title', $annotations);
        $this->assertEquals('Test Tool Annotations', $annotations['title']);
    }

    public function testInitializeMetadataWithDestructiveHint(): void
    {
        $tool = new DestructiveTool();
        $annotations = $tool->getAnnotations();
        $this->assertNotNull($annotations);
        $this->assertArrayHasKey('destructiveHint', $annotations);
        $this->assertTrue($annotations['destructiveHint']);
    }

    public function testInitializeMetadataWithIdempotentHint(): void
    {
        $tool = new IdempotentTool();
        $annotations = $tool->getAnnotations();
        $this->assertNotNull($annotations);
        $this->assertArrayHasKey('idempotentHint', $annotations);
        $this->assertTrue($annotations['idempotentHint']);
    }

    public function testInitializeMetadataWithOpenWorldHint(): void
    {
        $tool = new OpenWorldTool();
        $annotations = $tool->getAnnotations();
        $this->assertNotNull($annotations);
        $this->assertArrayHasKey('openWorldHint', $annotations);
        $this->assertTrue($annotations['openWorldHint']);
    }

    public function testInitializeMetadataWithReadOnlyHint(): void
    {
        $tool = new ReadOnlyTool();
        $annotations = $tool->getAnnotations();
        $this->assertNotNull($annotations);
        $this->assertArrayHasKey('readOnlyHint', $annotations);
        $this->assertTrue($annotations['readOnlyHint']);
    }

    public function testInitializeMetadataWithNoAnnotations(): void
    {
        // Use an anonymous class that extends Tool without any ToolAnnotations attribute
        $tool = new class extends Tool {
            protected function doExecute(array $arguments): array|ContentItemInterface
            {
                return $this->text("test");
            }
        };
        $annotations = $tool->getAnnotations();
        $this->assertNull($annotations, "Annotations should be null when no ToolAnnotations attribute is present.");
    }

    public function testInitializeMetadataWithEmptyToolAnnotations(): void
    {
        $tool = new EmptyAnnotationsTool();
        $annotations = $tool->getAnnotations();
        $this->assertNull($annotations, "Annotations should be null when ToolAnnotations attribute is empty.");
    }

    public function testInitializeMetadataWithAllHintsNullInToolAnnotations(): void
    {
        $tool = new AllNullAnnotationsTool();
        $annotations = $tool->getAnnotations();
        $this->assertNull($annotations, "Annotations should be null when all hints in ToolAnnotations are null.");
    }
}
