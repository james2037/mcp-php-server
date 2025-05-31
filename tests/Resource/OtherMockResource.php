<?php

namespace MCP\Server\Tests\Resource;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;

#[ResourceUri('test://two')]
class OtherMockResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text('Resource Two');
    }
}
