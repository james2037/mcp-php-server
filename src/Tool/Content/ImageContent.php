<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an image content item, typically containing base64 encoded image data and its MIME type.
 */
final class ImageContent extends AbstractMediaContent // Extend AbstractMediaContent
{
    // Properties $data and $mimeType are inherited from AbstractMediaContent
    // Property $annotations is inherited from AbstractContent (via AbstractMediaContent)

    /**
     * Constructs a new ImageContent instance.
     *
     * @param string $base64Data The base64 encoded image data.
     * @param string $mimeType The MIME type of the image.
     * @param Annotations|null $annotations Optional annotations.
     */
    public function __construct(
        string $base64Data,
        string $mimeType,
        ?Annotations $annotations = null
    ) {
        parent::__construct($base64Data, $mimeType, $annotations); // Call AbstractMediaContent constructor
    }

    /**
     * Returns the specific media type string.
     *
     * @return string
     */
    protected function getMediaType(): string
    {
        return 'image';
    }

    /**
     * Converts the image content to an array.
     * Delegates to AbstractMediaContent's toArray method.
     *
     * @return array<string, mixed> The array representation of the image content.
     */
    public function toArray(): array
    {
        return parent::toArray(); // All logic is now in AbstractMediaContent
    }
}
