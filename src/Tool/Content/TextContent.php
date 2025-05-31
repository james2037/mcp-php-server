<?php

/**
 * This file contains the TextContent class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents a text content item.
 */
final class TextContent implements ContentItemInterface
{
    private string $text;
    private ?Annotations $annotations;

    /**
     * Constructs a new TextContent instance.
     *
     * @param string $text The text content.
     * @param Annotations|null $annotations Optional annotations.
     */
    public function __construct(string $text, ?Annotations $annotations = null)
    {
        $this->text = $text;
        $this->annotations = $annotations;
    }

    /**
     * Converts the text content to an array.
     *
     * @return array The array representation of the text content.
     */
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
