<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\AbstractMediaContent;
use MCP\Server\Tool\Content\Annotations;

/**
 * Concrete implementation for testing AbstractMediaContent.
 */
class MockMediaContent extends AbstractMediaContent
{
    private string $mediaType;

    public function __construct(
        string $mediaType, // To control the output of getMediaType for testing
        string $base64Data,
        string $mimeType,
        ?Annotations $annotations = null
    ) {
        parent::__construct($base64Data, $mimeType, $annotations);
        $this->mediaType = $mediaType;
    }

    protected function getMediaType(): string
    {
        return $this->mediaType;
    }

    // toArray is inherited and tested via this mock
}
