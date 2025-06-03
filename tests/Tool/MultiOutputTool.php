<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\ContentItemInterface; // Already correctly namespaced
use MCP\Server\Tool\Tool;

#[ToolAttribute('multi-output', 'Tests multiple output types')]
class MultiOutputTool extends Tool
{
    /**
     * Returns content in the specified format.
     *
     * @param string $format Output format ('text' or 'image').
     * @return ContentItemInterface[]
     */
    protected function executeTool(
        #[ParameterAttribute('format', type: 'string', description: 'Output format (text/image)', required: true)]
        string $format
    ): array {
        if ($format === 'text') {
            return [$this->createTextContent('Hello world')];
        } elseif ($format === 'image') {
            return [$this->createImageContent('fake-image-data', 'image/png')];
        }
        throw new \InvalidArgumentException("Invalid format specified. Must be 'text' or 'image'.");
    }

    protected function doExecute(array $arguments): array
    {
        // Base validateArguments should have ensured 'format' is present and is a string.
        return $this->executeTool($arguments['format']); // Assumed string
    }
}
