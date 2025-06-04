<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('multi-output', 'Tests multiple output types')]
class MultiOutputTool extends Tool
{
    /**
     * @return array<ContentItemInterface>
     */
    protected function doExecute(
        #[ParameterAttribute('format', type: 'string', description: 'Output format (text/image)')]
        array $arguments
    ): array {
        if ($arguments['format'] === 'text') {
            return [$this->text('Hello world')];
        }
        return [$this->image('fake-image-data', 'image/png')];
    }
}
