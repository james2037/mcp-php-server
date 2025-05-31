<?php

/**
 * This file contains the ImageContent class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents an image content item.
 */
final class ImageContent implements ContentItemInterface
{
    private string $data; // base64 encoded
    private string $mimeType;
    private ?Annotations $annotations;

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
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
        $this->annotations = $annotations;
    }

    /**
     * Converts the image content to an array.
     *
     * @return array The array representation of the image content.
     */
    public function toArray(): array
    {
        $data = [
            'type' => 'image',
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
