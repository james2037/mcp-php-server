<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

abstract class ResourceContents
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $mimeType = null
    ) {
    }
}
