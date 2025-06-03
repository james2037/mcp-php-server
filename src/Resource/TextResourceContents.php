<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

/**
 * Represents the contents of a resource as plain text.
 * The MIME type defaults to "text/plain" if not specified.
 */
class TextResourceContents extends ResourceContents
{
    /**
     * Constructs a TextResourceContents instance.
     *
     * @param string $uri The URI of the resource.
     * @param string $text The text content.
     * @param string|null $mimeType The MIME type of the content. Defaults to "text/plain" if null.
     */
    public function __construct(
        string $uri,
        public readonly string $text,
        ?string $mimeType = null
    ) {
        parent::__construct($uri, $mimeType ?? 'text/plain');
    }

    /**
     * Converts the text resource contents to an array format.
     * Includes 'uri', 'text', and 'mimeType'.
     *
     * @return array{uri: string, text: string, mimeType?: string} The array representation.
     */
    public function toArray(): array
    {
        $data = ['uri' => $this->uri, 'text' => $this->text];
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        return $data;
    }
}
