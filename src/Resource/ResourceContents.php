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
     * Constructs a ResourceContents instance.
     *
     * @param string $uri The URI of the resource content. This might be a resolved URI if the resource URI was a template.
     * @param string|null $mimeType The MIME type of the resource content, if applicable.
     */
    public function __construct(
        /** The URI of the resource content. */
        public readonly string $uri,
        /** The MIME type of the resource content, if applicable. */
        public readonly ?string $mimeType = null
    ) {
    }
}
