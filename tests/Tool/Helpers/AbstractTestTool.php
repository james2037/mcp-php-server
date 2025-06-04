<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Helpers;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('abstract-test-tool', 'Abstract Test Tool')]
abstract class AbstractTestTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     */
    abstract protected function doExecute(array $arguments): array;
}
