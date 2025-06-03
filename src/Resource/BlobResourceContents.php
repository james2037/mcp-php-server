<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

/**
 * Represents the contents of a resource as a base64-encoded binary blob.
 */
class BlobResourceContents extends ResourceContents
{
    /**
     * Constructs a BlobResourceContents instance.
     *
     * @param string $uri The URI of the resource.
     * @param string $blob The base64-encoded binary content.
     * @param string $mimeType The MIME type of the content.
     */
    public function __construct(
        string $uri,
        public readonly string $blob,
        string $mimeType
    ) {
        parent::__construct($uri, $mimeType);
    }

    /**
     * Converts the blob resource contents to an array format.
     * Includes 'uri', 'blob', and 'mimeType'.
     *
     * @return array{uri: string, blob: string, mimeType?: string} The array representation.
     */
    public function toArray(): array
    {
        $data = ['uri' => $this->uri, 'blob' => $this->blob];
        // $this->mimeType is guaranteed by constructor, but check doesn't hurt for consistency
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        return $data;
    }
}
