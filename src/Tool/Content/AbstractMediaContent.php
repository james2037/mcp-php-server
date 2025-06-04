<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Abstract base class for media content items like audio and images.
 * Manages common properties like base64 data and MIME type.
 */
abstract class AbstractMediaContent extends AbstractContent
{
    protected string $data;
    protected string $mimeType;

    /**
     * Constructs a new AbstractMediaContent instance.
     *
     * @param string $base64Data The base64 encoded media data.
     * @param string $mimeType The MIME type of the media.
     * @param Annotations|null $annotations Optional annotations.
     */
    public function __construct(
        string $base64Data,
        string $mimeType,
        ?Annotations $annotations = null
    ) {
        parent::__construct($annotations);
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
    }

    /**
     * Returns the specific media type string (e.g., "audio", "image").
     *
     * @return string
     */
    abstract protected function getMediaType(): string;

    /**
     * Converts the media content item to an array.
     *
     * @return array<string, mixed> The array representation of the media content.
     */
    public function toArray(): array
    {
        $mediaData = [
            'type' => $this->getMediaType(),
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        // Merge with annotations from AbstractContent's toArray method
        return array_merge($mediaData, parent::toArray());
    }
}
