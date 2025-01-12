<?php

declare(strict_types=1);

namespace MCP\Server\Resource\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ResourceUri
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $description = null
    ) {
    }
}
