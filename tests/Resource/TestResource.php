<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;

#[ResourceUri('test://static')]
class TestResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text('Static content', null, $parameters);
    }
}
