<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Exception\InvalidParamsException; // For argument validation
use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Resource\TextResourceContents;
use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\ContentItemInterface; // Added for return type
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute(
    name: 'replace_text',
    description: 'Replaces text in a file.',
)]
final class ReplaceTextTool extends Tool
{
    private ResourceRegistry $resourceRegistry;

    public function __construct(ResourceRegistry $resourceRegistry)
    {
        parent::__construct(null);
        $this->resourceRegistry = $resourceRegistry;
    }

    /**
     * Executes the core logic of the tool.
     *
     * @param array<string,mixed> $arguments Validated arguments for the tool.
     * @return array<int, ContentItemInterface> An array of content items.
     */
    protected function doExecute(array $arguments): array
    {
        $filePath = $arguments['filePath'] ?? null;
        $searchText = $arguments['searchText'] ?? null;
        $replaceText = $arguments['replaceText'] ?? null;

        if (!is_string($filePath) || !is_string($searchText) || !is_string($replaceText)) {
            throw new InvalidParamsException('Invalid arguments provided for ReplaceTextTool.');
        }

        $resources = $this->resourceRegistry->getResources();
        if (!isset($resources[$filePath])) {
            throw new \RuntimeException("Resource '$filePath' not found.");
        }
        $resource = $resources[$filePath];

        $readContents = $resource->read();
        if (!$readContents instanceof TextResourceContents) {
            throw new \RuntimeException("Resource '$filePath' did not provide text content.");
        }
        $contents = $readContents->text;

        $newContents = str_replace($searchText, $replaceText, $contents);

        // TODO: Check if Resource has a write() method.
        // If $resource->write() does not exist, this tool cannot function as intended.
        if (method_exists($resource, 'write')) {
            $resource->write(new TextResourceContents($filePath, $newContents));
            return [$this->createTextContent("Successfully replaced text in {$filePath}.")];
        } else {
            return [$this->createTextContent("Error: Resource '{$filePath}' is not writable. Content was not saved. Modified content: {$newContents}")];
        }
    }

    /**
     * @param string $filePath
     * @param string $searchText
     * @param string $replaceText
     * @return array<int, ContentItemInterface>
     */
    public function __invoke(
        #[Parameter(name: 'filePath', type: 'string', description: 'The path to the file.', required: true)]
        string $filePath,
        #[Parameter(name: 'searchText', type: 'string', description: 'The text to search for.', required: true)]
        string $searchText,
        #[Parameter(name: 'replaceText', type: 'string', description: 'The text to replace with.', required: true)]
        string $replaceText
    ): array {
        return $this->doExecute([
            'filePath' => $filePath,
            'searchText' => $searchText,
            'replaceText' => $replaceText,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDefaultConfig(): array
    {
        return [];
    }
}
