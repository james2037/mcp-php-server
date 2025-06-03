<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an audio content item, typically containing base64 encoded audio data and its MIME type.
 */
final class AudioContent implements ContentItemInterface
{
    /** @var string Base64 encoded audio data. */
    private string $data;
    /** @var string The MIME type of the audio data (e.g., "audio/mpeg"). */
    private string $mimeType;
    /** @var Annotations|null Optional annotations for the audio content. */
    private ?Annotations $annotations;

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
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
        $this->annotations = $annotations;
    }

    /**
     * Converts the audio content to an array.
     *
     * @return array<string, mixed> The array representation of the audio content.
     */
    public function toArray(): array
    {
        $data = [
            'type' => 'audio',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        if ($this->annotations !== null) {
            $annotationsArray = $this->annotations->toArray();
            if (!empty($annotationsArray)) {
                $data['annotations'] = $annotationsArray;
            }
        }
        return $data;
    }
}
