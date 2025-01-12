<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

class BlobResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public readonly string $blob,
        string $mimeType
    ) {
        parent::__construct($uri, $mimeType);
    }
}
