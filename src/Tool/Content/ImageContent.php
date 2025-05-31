<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

final class ImageContent implements ContentItemInterface
{
    private string $data; // base64 encoded
    private string $mimeType;
    private ?Annotations $annotations;

    public function __construct(string $base64Data, string $mimeType, ?Annotations $annotations = null)
    {
        $this->data = $base64Data;
        $this->mimeType = $mimeType;
        $this->annotations = $annotations;
    }

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
