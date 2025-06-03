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
    /**
     * Lists the contents of a specified directory.
     *
     * @param string $directoryPath The path to the directory.
     * @return TextContent[] An array containing a single TextContent item with the directory listing.
     * @throws \InvalidArgumentException If the directory is not found or path is invalid.
     * @throws \RuntimeException If the directory is not readable or scandir fails.
     */
    protected function executeTool(
        #[ParameterAttribute(name: "directory_path", type: "string", description: "The path to the directory.", required: true)]
        string $directoryPath
    ): array {
        // Type validation (is_string) is handled by PHP type hinting and base class validation.
        // Required validation is handled by base class validation.

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

    protected function doExecute(array $arguments): array
    {
        $directoryPath = $arguments['directory_path'] ?? null;

        if ($directoryPath === null) {
            // This should be caught by validateArguments due to 'required: true'
            throw new \InvalidArgumentException("Missing required argument: directory_path");
        }
        // Type validation for $directoryPath (ensuring it's a string) will be handled by
        // Tool::validateArguments based on the Parameter attribute.

        return $this->executeTool((string)$directoryPath);
    }
}
