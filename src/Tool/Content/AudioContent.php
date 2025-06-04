<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an audio content item, typically containing base64 encoded audio data and its MIME type.
 */
final class AudioContent extends AbstractMediaContent // Extend AbstractMediaContent
{
    // Properties $data and $mimeType are inherited from AbstractMediaContent
    // Property $annotations is inherited from AbstractContent (via AbstractMediaContent)

    /**
     * Constructs a new AudioContent instance.
     *
     * @param string $base64Data The base64 encoded audio data.
     * @param string $mimeType The MIME type of the audio.
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
        return 'audio';
    }

    /**
     * Converts the audio content to an array.
     * Delegates to AbstractMediaContent's toArray method.
     *
     * @return array<string, mixed> The array representation of the audio content.
     */
    public function toArray(): array
    {
        return parent::toArray(); // All logic is now in AbstractMediaContent
    }
}
