<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use MCP\Server\Tool\Content\ImageContent;
use PHPUnit\Framework\TestCase;

class ImageContentTest extends TestCase
{
    public function testToArrayBasic(): void
    {
        $imageContent = new ImageContent('base64imgdata', 'image/png');
        $expected = [
            'type' => 'image',
            'data' => 'base64imgdata',
            'mimeType' => 'image/png',
        ];
        $this->assertEquals($expected, $imageContent->toArray());
    }

    public function testToArrayWithAnnotations(): void
    {
        $annotations = new Annotations(['user', 'assistant'], 1.0);
        $imageContent = new ImageContent('base64jpegdata', 'image/jpeg', $annotations);
        $expected = [
            'type' => 'image',
            'data' => 'base64jpegdata',
            'mimeType' => 'image/jpeg',
            'annotations' => [
                'audience' => ['user', 'assistant'],
                'priority' => 1.0,
            ],
        ];
        $this->assertEquals($expected, $imageContent->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        $annotations = new Annotations(null, null);
        $imageContent = new ImageContent('base64gifdata', 'image/gif', $annotations);
        $expected = [
            'type' => 'image',
            'data' => 'base64gifdata',
            'mimeType' => 'image/gif',
            // No 'annotations' key
        ];
        $this->assertEquals($expected, $imageContent->toArray());
    }
}
