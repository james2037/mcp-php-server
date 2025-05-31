<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

final class ImageContent implements ContentItemInterface
{
    private string $_data; // base64 encoded
    private string $_mimeType;
    private ?Annotations $_annotations;

    public function __construct(string $base64Data, string $mimeType, ?Annotations $annotations = null)
    {
        $this->_data = $base64Data;
        $this->_mimeType = $mimeType;
        $this->_annotations = $annotations;
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'image',
            'data' => $this->_data,
            'mimeType' => $this->_mimeType,
        ];

        if ($this->_annotations !== null) {
            $annotationsArray = $this->_annotations->toArray();
            if (!empty($annotationsArray)) {
                $data['annotations'] = $annotationsArray;
            }
        }
        return $data;
    }
}
