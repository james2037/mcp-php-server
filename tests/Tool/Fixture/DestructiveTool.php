<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Fixture;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAnnotations(destructiveHint: true)]
class DestructiveTool extends Tool
{
    protected function doExecute(array $arguments): array|ContentItemInterface
    {
        return $this->text("test");
    }
}
