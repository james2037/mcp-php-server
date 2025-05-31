<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Tool\Content\Annotations;

#[ResourceUri('test://users/{userId}', 'Parameterized user resource')]
class ResourcesCapabilityParameterizedResource extends Resource // Renamed class
{
    public function __construct(string $name, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null)
    {
        parent::__construct($name, $mimeType, $size, $annotations);
    }

    public function read(array $parameters = []): ResourceContents
    {
        if (!isset($parameters['userId'])) {
            throw new \RuntimeException('Missing userId parameter');
        }
        return parent::text("User {$parameters['userId']}", $this->mimeType, $parameters);
    }
}
