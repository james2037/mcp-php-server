<?php

declare(strict_types=1);

namespace MCP\Tests\Server\Resource;

use MCP\Server\Resource\TextResourceContents;
use PHPUnit\Framework\TestCase;

class TextResourceContentsTest extends TestCase
{
    public function testConstructorAndToArrayWithExplicitMimeType(): void
    {
        $uri = 'test://text/1';
        $text = 'Hello world';
        $mimeType = 'application/xml'; // Explicitly different from default

        $contents = new TextResourceContents($uri, $text, $mimeType);

        $this->assertSame($uri, $contents->uri);
        $this->assertSame($text, $contents->text);
        $this->assertSame($mimeType, $contents->mimeType);

        $expectedArray = [
            'uri' => $uri,
            'mimeType' => $mimeType,
            'text' => $text,
        ];
        $this->assertSame($expectedArray, $contents->toArray());
    }

    public function testConstructorAndToArrayWithDefaultMimeType(): void
    {
        $uri = 'test://text/2';
        $text = 'Another message';
        $defaultMimeType = 'text/plain'; // Expected default

        // Instantiate without providing mimeType to use the default
        $contents = new TextResourceContents($uri, $text);

        $this->assertSame($uri, $contents->uri);
        $this->assertSame($text, $contents->text);
        $this->assertSame($defaultMimeType, $contents->mimeType);

        $expectedArray = [
            'uri' => $uri,
            'mimeType' => $defaultMimeType,
            'text' => $text,
        ];
        $this->assertSame($expectedArray, $contents->toArray());
    }
}
