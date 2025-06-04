<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('greeter', 'A friendly greeter')]
class OptionalParamTool extends Tool
{
    /**
     * @return ContentItemInterface
     */
    protected function doExecute(
        #[ParameterAttribute('name', type: 'string', description: 'Name to greet')]
        #[ParameterAttribute('title', type: 'string', description: 'Optional title', required: false)]
        array $arguments
    ): \MCP\Server\Tool\Content\ContentItemInterface {
        $title = $arguments['title'] ?? 'friend';
        return $this->text("Hello {$title} {$arguments['name']}");
    }
}
