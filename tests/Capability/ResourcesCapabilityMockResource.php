<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Tool\Content\Annotations;

#[ResourceUri('test://static', 'A static mock resource')]
class ResourcesCapabilityMockResource extends Resource // Renamed class
{
    // Constructor to pass name, mimeType, size, annotations to parent
    public function __construct(string $name, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null)
    {
        parent::__construct($name, $mimeType, $size, $annotations);
    }

    public function read(array $parameters = []): ResourceContents
    {
        return parent::text('Static content', $this->mimeType);
    }
}
