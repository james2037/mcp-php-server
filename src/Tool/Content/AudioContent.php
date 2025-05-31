<?php

/**
 * This file contains the AudioContent class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an audio content item.
 */
final class AudioContent implements ContentItemInterface
{
    private string $data; // base64 encoded
    private string $mimeType;
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
     * @return array The array representation of the audio content.
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
