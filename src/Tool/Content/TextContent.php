<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents a plain text content item.
 */
final class TextContent implements ContentItemInterface
{
    /** @var string The plain text content. */
    private string $text;
    /** @var Annotations|null Optional annotations for the text content. */
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
     * @return array<string, mixed> The array representation of the text content.
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
