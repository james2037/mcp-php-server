<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

class TextResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public readonly string $text,
        ?string $mimeType = null
    ) {
        parent::__construct($uri, $mimeType);
    }
}
