<?php

declare(strict_types=1);

namespace MCP\Tests\Server\Resource;

use MCP\Server\Resource\BlobResourceContents;
use PHPUnit\Framework\TestCase;

class BlobResourceContentsTest extends TestCase
{
    public function testToArrayReturnsCorrectStructureAndValues(): void
    {
        $uri = 'test://blob/123';
        $blobData = base64_encode('Hello World');
        $mimeType = 'application/octet-stream';

        $blobContents = new BlobResourceContents($uri, $blobData, $mimeType);

        $expectedArray = [
            'uri' => $uri,
            'blob' => $blobData,
            'mimeType' => $mimeType,
        ];

        $this->assertSame($expectedArray, $blobContents->toArray());
    }
}
