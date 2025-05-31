<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

final class TextContent implements ContentItemInterface
{
    private string $text;
    private ?Annotations $annotations;

    public function __construct(string $text, ?Annotations $annotations = null)
    {
        $this->text = $text;
        $this->annotations = $annotations;
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'text',
            'text' => $this->text,
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
