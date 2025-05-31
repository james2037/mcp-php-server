<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

final class TextContent implements ContentItemInterface
{
    private string $_text;
    private ?Annotations $_annotations;

    public function __construct(string $text, ?Annotations $annotations = null)
    {
        $this->_text = $text;
        $this->_annotations = $annotations;
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'text',
            'text' => $this->_text,
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
