<?php

namespace MCP\Server\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;
use MCP\Server\Tool\Content\TextContent;

#[ToolAttribute(name: "directory/list", description: "Lists the contents of a directory.")]
class DirectoryListingTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute(name: "directory_path", type: "string", description: "The path to the directory.", required: true)]
        array $arguments
    ): array {
        $directoryPath = $arguments['directory_path'];

        if (!is_string($directoryPath)) {
            throw new \InvalidArgumentException("Directory path must be a string.");
        }

        if (!is_dir($directoryPath)) {
            throw new \InvalidArgumentException("Directory not found at path: " . $directoryPath);
        }

        if (!is_readable($directoryPath)) {
            throw new \RuntimeException("Directory is not readable: " . $directoryPath);
        }

        $files = scandir($directoryPath);

        if ($files === false) {
            throw new \RuntimeException("Unable to read directory contents from path: " . $directoryPath);
        }

        // Filter out '.' and '..'
        $files = array_filter($files, function ($file) {
            return !in_array($file, ['.', '..']);
        });

        return [$this->createTextContent(implode("\n", $files))];
    }
}
