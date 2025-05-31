<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('multi-output', 'Tests multiple output types')]
class MultiOutputTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('format', type: 'string', description: 'Output format (text/image)')]
        array $arguments
    ): array {
        if ($arguments['format'] === 'text') {
            return [$this->createTextContent('Hello world')];
        }
        return [$this->createImageContent('fake-image-data', 'image/png')];
    }
}
