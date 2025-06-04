<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use MCP\Server\Tool\Content\TextContent;
use PHPUnit\Framework\TestCase;

class TextContentTest extends TestCase
{
    public function testToArrayBasic(): void
    {
        $textContent = new TextContent('Hello, world!');
        $expected = [
            'type' => 'text',
            'text' => 'Hello, world!',
        ];
        $this->assertEquals($expected, $textContent->toArray());
    }

    public function testToArrayWithAnnotations(): void
    {
        $annotations = new Annotations(['user'], 0.5);
        $textContent = new TextContent('Important message', $annotations);
        $expected = [
            'type' => 'text',
            'text' => 'Important message',
            'annotations' => [
                'audience' => ['user'],
                'priority' => 0.5,
            ],
        ];
        $this->assertEquals($expected, $textContent->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        $annotations = new Annotations(null, null);
        $textContent = new TextContent('Just some text', $annotations);
        $expected = [
            'type' => 'text',
            'text' => 'Just some text',
            // No 'annotations' key
        ];
        $this->assertEquals($expected, $textContent->toArray());
    }
}
