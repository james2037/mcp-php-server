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
     * @param string $mimeType The MIME type of the resource. Defaults to 'text/plain'.
     */
    public function __construct(
        string $uri,
        public readonly string $text,
        string $mimeType = 'text/plain'
    ) {
        parent::__construct($uri, $mimeType);
    }

    /**
     * Converts the text resource contents to an array format.
     * Includes 'uri', 'mimeType', and 'text'.
     *
     * @return array{uri: string, mimeType: string, text: string} The array representation.
     */
    public function toArray(): array
    {
        $data = parent::toArray(); // Gets 'uri' and 'mimeType' from ResourceContents
        $data['text'] = $this->text;
        return $data;
    }
}
