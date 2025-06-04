<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Represents a plain text content item.
 */
final class TextContent extends AbstractContent // Extend AbstractContent
{
    /** @var string The plain text content. */
    private string $text;
    // Remove private ?Annotations $annotations;

    /**
     * Constructs a new TextContent instance.
     *
     * @param string $text The text content.
     * @param Annotations|null $annotations Optional annotations.
     */
    public function __construct(string $text, ?Annotations $annotations = null)
    {
        parent::__construct($annotations); // Call parent constructor
        $this->text = $text;
    }

    /**
     * Converts the text content to an array.
     *
     * @return array<string, mixed> The array representation of the text content.
     */
    public function toArray(): array
    {
        $contentData = [
            'type' => 'text',
            'text' => $this->text,
        ];

        return array_merge($contentData, parent::toArray()); // Merge with parent::toArray()
    }
}
