<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an audio content item, typically containing base64 encoded audio data and its MIME type.
 */
final class AudioContent extends AbstractContent // Extend AbstractContent
{
    /** @var string Base64 encoded audio data. */
    private string $data;
    /** @var string The MIME type of the audio data (e.g., "audio/mpeg"). */
    private string $mimeType;
    // Remove private ?Annotations $annotations;

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
        parent::__construct($annotations); // Call parent constructor
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
    }

    /**
     * Converts the audio content to an array.
     *
     * @return array<string, mixed> The array representation of the audio content.
     */
    public function toArray(): array
    {
        $contentData = [
            'type' => 'audio',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        return array_merge($contentData, parent::toArray()); // Merge with parent::toArray()
    }
}
