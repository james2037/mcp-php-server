<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;

#[ResourceUri('test://users/{userId}/profile')]
class DynamicResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text("Profile for user {$parameters['userId']}", null, $parameters);
    }
}
