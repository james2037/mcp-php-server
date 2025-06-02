<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Tool\Content\Annotations;

#[ResourceUri('test://erroronread')]
class ResourcesCapabilityErrorOnReadResource extends Resource
{
    public function __construct(string $name, string $mimeType, ?Annotations $annotations = null)
    {
        parent::__construct(
            name: $name,
            mimeType: $mimeType,
            size: null, // Size is not relevant for this test
            annotations: $annotations
        );
    }

    public function read(array $parameters = []): ResourceContents
    {
        throw new \Exception('Mock error reading resource');
    }
}
