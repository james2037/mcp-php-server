<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use MCP\Server\Tool\Content\AudioContent;
use PHPUnit\Framework\TestCase;

class AudioContentTest extends TestCase
{
    public function testToArrayBasic(): void
    {
        $audioContent = new AudioContent('base64data', 'audio/mp3');
        $expected = [
            'type' => 'audio',
            'data' => 'base64data',
            'mimeType' => 'audio/mp3',
        ];
        $this->assertEquals($expected, $audioContent->toArray());
    }

    public function testToArrayWithAnnotations(): void
    {
        $annotations = new Annotations(['user'], 0.9);
        $audioContent = new AudioContent('base64data2', 'audio/wav', $annotations);
        $expected = [
            'type' => 'audio',
            'data' => 'base64data2',
            'mimeType' => 'audio/wav',
            'annotations' => [
                'audience' => ['user'],
                'priority' => 0.9,
            ],
        ];
        $this->assertEquals($expected, $audioContent->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        $annotations = new Annotations(null, null); // Creates an "empty" annotations object
        $audioContent = new AudioContent('base64data3', 'audio/ogg', $annotations);
        $expected = [
            'type' => 'audio',
            'data' => 'base64data3',
            'mimeType' => 'audio/ogg',
            // No 'annotations' key because the Annotations object's toArray() is empty
        ];
        $this->assertEquals($expected, $audioContent->toArray());
    }
}
