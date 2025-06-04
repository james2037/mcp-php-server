<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use PHPUnit\Framework\TestCase;

// MockMediaContent is in its own file: MockMediaContent.php

class AbstractMediaContentTest extends TestCase
{
    public function testToArrayWithSpecificMediaTypeAndAnnotations(): void
    {
        $annotations = new Annotations(['user'], 0.8);
        $mediaContent = new MockMediaContent('video', 'videodata', 'video/mp4', $annotations);

        $expectedArray = [
            'type' => 'video', // From MockMediaContent's getMediaType()
            'data' => 'videodata',
            'mimeType' => 'video/mp4',
            'annotations' => [
                'audience' => ['user'],
                'priority' => 0.8,
            ],
        ];
        $this->assertEquals($expectedArray, $mediaContent->toArray());
    }

    public function testToArrayWithoutAnnotations(): void
    {
        $mediaContent = new MockMediaContent('audio_test', 'audiodata', 'audio/mpeg', null);

        $expectedArray = [
            'type' => 'audio_test',
            'data' => 'audiodata',
            'mimeType' => 'audio/mpeg',
            // No 'annotations' key
        ];
        $this->assertEquals($expectedArray, $mediaContent->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        $annotations = new Annotations(null, null);
        $mediaContent = new MockMediaContent('image_test', 'imagedata', 'image/jpeg', $annotations);

        $expectedArray = [
            'type' => 'image_test',
            'data' => 'imagedata',
            'mimeType' => 'image/jpeg',
            // No 'annotations' key because the annotations object itself results in an empty array
        ];
        $this->assertEquals($expectedArray, $mediaContent->toArray());
    }
}
