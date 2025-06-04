<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Helpers;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Content\ContentItemInterface;

class NoAttributeTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     */
    protected function doExecute(array $arguments): array
    {
        return []; // Dummy implementation
    }
}
