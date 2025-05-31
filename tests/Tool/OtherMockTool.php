<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
// ParameterAttribute is not used in this class, so it's not strictly necessary to import,
// but kept for consistency if it might be added later or to avoid confusion.
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('other', 'Other Tool')]
class OtherMockTool extends Tool
{
    protected function doExecute(array $arguments): array
    {
        return [$this->createTextContent('Other Result')];
    }
}
