<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Helpers;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;

#[ToolAttribute('abstract-test-tool', 'Abstract Test Tool')]
abstract class AbstractTestTool extends Tool
{
    abstract protected function doExecute(array $arguments): array;
}
