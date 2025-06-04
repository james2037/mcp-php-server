<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

/**
 * Abstract base class for the contents of a resource.
 * It holds the URI from which the content was resolved and an optional MIME type.
 * Specific content types (e.g., text, blob) should extend this class.
 */
abstract class ResourceContents
{
    /**
     * The MIME type of the resource content.
     * @var string
     */
    public readonly string $mimeType;

    /**
     * Constructs a ResourceContents instance.
     *
     * @param string $uri The URI of the resource content. This might be a resolved URI if the resource URI was a template.
     * @param string $mimeType The MIME type of the resource content.
     */
    public function __construct(
        /** The URI of the resource content. */
        public readonly string $uri,
        string $mimeType
    ) {
        $this->mimeType = $mimeType;
    }

    /**
     * Converts the resource contents to an array format.
     *
     * @return array{uri: string, mimeType: string} The array representation.
     */
    public function toArray(): array
    {
        return ['uri' => $this->uri, 'mimeType' => $this->mimeType];
    }
}
