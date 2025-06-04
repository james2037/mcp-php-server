<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use MCP\Server\Tool\Content\EmbeddedResource;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class EmbeddedResourceTest extends TestCase
{
    public function testToArrayWithTextResource(): void
    {
        $resourceData = ['uri' => '/my/data', 'text' => 'hello', 'mimeType' => 'text/plain'];
        $embeddedResource = new EmbeddedResource($resourceData);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }

    public function testToArrayWithBlobResource(): void
    {
        $resourceData = ['uri' => '/my/image', 'blob' => 'base64data', 'mimeType' => 'image/png'];
        $embeddedResource = new EmbeddedResource($resourceData);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }

    public function testToArrayWithAnnotations(): void
    {
        $resourceData = ['uri' => '/my/doc', 'text' => 'documentation'];
        $annotations = new Annotations(['assistant'], 0.7);
        $embeddedResource = new EmbeddedResource($resourceData, $annotations);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
            'annotations' => [
                'audience' => ['assistant'],
                'priority' => 0.7,
            ],
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }

    public function testConstructorThrowsErrorIfNoTextAndNoBlob(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("EmbeddedResource data must contain either a 'text' or a 'blob' key.");
        new EmbeddedResource(['uri' => '/my/invalid']);
    }

    public function testConstructorAllowsTextOnly(): void
    {
        $resourceData = ['uri' => '/my/textonly', 'text' => 'some text'];
        $embeddedResource = new EmbeddedResource($resourceData); // Should not throw
        $this->assertInstanceOf(EmbeddedResource::class, $embeddedResource);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }

    public function testConstructorAllowsBlobOnly(): void
    {
        $resourceData = ['uri' => '/my/blobonly', 'blob' => 'some blob data'];
        $embeddedResource = new EmbeddedResource($resourceData); // Should not throw
        $this->assertInstanceOf(EmbeddedResource::class, $embeddedResource);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        $resourceData = ['uri' => '/my/data', 'text' => 'hello'];
        $annotations = new Annotations(null, null);
        $embeddedResource = new EmbeddedResource($resourceData, $annotations);
        $expected = [
            'type' => 'resource',
            'resource' => $resourceData,
            // No 'annotations' key
        ];
        $this->assertEquals($expected, $embeddedResource->toArray());
    }
}
