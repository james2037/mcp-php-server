<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an image content item, typically containing base64 encoded image data and its MIME type.
 */
final class ImageContent extends AbstractContent // Extend AbstractContent
{
    /** @var string Base64 encoded image data. */
    private string $data;
    /** @var string The MIME type of the image data (e.g., "image/png", "image/jpeg"). */
    private string $mimeType;
    // Remove private ?Annotations $annotations;

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
        parent::__construct($annotations); // Call parent constructor
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
    }

    /**
     * Converts the image content to an array.
     *
     * @return array<string, mixed> The array representation of the image content.
     */
    public function toArray(): array
    {
        $contentData = [
            'type' => 'image',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        return array_merge($contentData, parent::toArray()); // Merge with parent::toArray()
    }
}
