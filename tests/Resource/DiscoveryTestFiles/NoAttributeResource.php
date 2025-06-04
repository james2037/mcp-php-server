<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Resource\DiscoveryTestFiles;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;

/**
 * A resource class that intentionally lacks the ResourceUri attribute.
 * This is to test that ResourceRegistry::createFromReflection skips it.
 */
class NoAttributeResource extends Resource
{
    public function __construct()
    {
        // Call parent constructor with placeholder values as this resource
        // shouldn't be registered or used by URI anyway.
        parent::__construct("NoAttributeResource", "text/plain");
    }

    public function read(array $parameters = []): ResourceContents
    {
        // Implementation for read, though it shouldn't be called if not registered.
        return $this->text("This resource should not be discovered.");
    }
}
