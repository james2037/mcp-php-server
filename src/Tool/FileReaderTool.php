<?php

namespace MCP\Server\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;
use MCP\Server\Tool\Content\TextContent;

#[ToolAttribute(name: "file/read", description: "Reads the content of a file.")]
class FileReaderTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute(name: "filepath", type: "string", description: "The path to the file.", required: true)]
        array $arguments
    ): array {
        $filepath = $arguments['filepath'];
        if (!is_string($filepath)) {
            throw new \InvalidArgumentException("Filepath must be a string.");
        }

        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException("File not found at path: " . $filepath);
        }

        $content = file_get_contents($filepath);

        if ($content === false) {
            throw new \RuntimeException("Unable to read file content from path: " . $filepath);
        }

        return [$this->createTextContent($content)];
    }
}
