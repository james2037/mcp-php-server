<?php

declare(strict_types=1);

namespace MCP\Server\Resource\Attribute;

use Attribute;

/**
 * PHP attribute to define the URI and description for a Resource class.
 * This allows associating a URI pattern and an optional description directly
 * with the class definition.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ResourceUri
{
    /**
     * @param string $uri The URI pattern for the resource. It can include placeholders like {param}.
     * @param string|null $description An optional description of the resource.
     */
    public function __construct(
        public readonly string $uri,
        public readonly ?string $description = null
    ) {
    }
}
