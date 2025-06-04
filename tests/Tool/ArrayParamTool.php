<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;

#[ToolAttribute('array-tool', 'Tests array parameters')]
class ArrayParamTool extends Tool
{
    /**
     * @return ContentItemInterface
     */
    protected function doExecute(
        #[ParameterAttribute('numbers', type: 'array', description: 'List of numbers')]
        #[ParameterAttribute('enabled', type: 'boolean', description: 'Whether processing is enabled')]
        array $arguments
    ): \MCP\Server\Tool\Content\ContentItemInterface {
        if (!$arguments['enabled']) {
            return $this->text('Processing disabled');
        }
        $sum = array_sum($arguments['numbers']);
        return $this->text("Sum: $sum");
    }
}
