<?php

namespace MCP\Server\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Content\ContentItemInterface;
use MCP\Server\Tool\Content\TextContent;

/**
 * A tool that provides functionality to read the content of a specified file.
 * It uses the `Tool` attribute to define its name as "file/read" and its description.
 * The `filepath` parameter is defined via a `Parameter` attribute on the `doExecute` method.
 */
#[ToolAttribute(name: "file/read", description: "Reads the content of a file.")]
class FileReaderTool extends Tool
{
    /**
     * Executes the file reading operation.
     *
     * This method reads the content of the file specified in the 'filepath' argument.
     *
     * @param array $arguments An associative array containing the arguments for the tool.
     *                         Expected to have a 'filepath' key with a string value,
     *                         as defined by the ParameterAttribute.
     * @return array<int, ContentItemInterface> An array containing a single TextContent item
     *                                          with the file's content.
     * @throws \InvalidArgumentException If 'filepath' is not a string or file does not exist.
     * @throws \RuntimeException If the file is not readable or content cannot be retrieved.
     */
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

        if (!is_readable($filepath)) {
            throw new \RuntimeException("File is not readable: " . $filepath);
        }
        $content = file_get_contents($filepath);

        if ($content === false) {
            throw new \RuntimeException("Unable to read file content from path: " . $filepath);
        }

        return [$this->createTextContent($content)];
    }
}
