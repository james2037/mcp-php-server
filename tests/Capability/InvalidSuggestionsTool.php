<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
// ParameterAttribute is not used in this file, TextContent is.
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute('invalidSuggestionsTool', 'Tool that returns invalid suggestions')]
class InvalidSuggestionsTool extends Tool
{
    /** @var array<string, mixed> The suggestions array, potentially malformed for testing. */
    private array $suggestionsToReturn;

    /** @param array<string, mixed> $suggestionsToReturn */
    public function __construct(array $suggestionsToReturn)
    {
        parent::__construct(); // Call parent constructor first
        $this->suggestionsToReturn = $suggestionsToReturn;
    }

    /**
     * Does nothing for execution, primary use is for completion suggestions.
     * @return TextContent[]
     */
    protected function executeTool(): array
    {
        return [];
    }

    protected function doExecute(array $arguments): array
    {
        // No arguments expected or used by executeTool
        return $this->executeTool();
    }

    // Signature must match Tool::getCompletionSuggestions
    /**
     * @param array<string, mixed> $allCurrentArguments
     * @return array<string, mixed>
     */
    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allCurrentArguments = []): array
    {
        // $allCurrentArguments is unused in this mock but part of the signature.
        return $this->suggestionsToReturn;
    }
}
