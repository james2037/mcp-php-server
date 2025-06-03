<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Tool;

#[ToolAttribute('invalidSuggestionsTool', 'Tool that returns invalid suggestions')]
class InvalidSuggestionsTool extends Tool
{
    /** @var array<string, mixed> The suggestions array, potentially malformed for testing. */
    private array $suggestionsToReturn;

    /** @param array<string, mixed> $suggestionsToReturn */
    public function __construct(array $suggestionsToReturn)
    {
        parent::__construct();
        $this->suggestionsToReturn = $suggestionsToReturn;
    }

    protected function doExecute(array $arguments): array
    {
        // Not used for completion tests
        return [];
    }

    // Signature must match Tool::getCompletionSuggestions
    /**
     * @param array<string, mixed> $allCurrentArguments
     * @return array{values: string[], total?: int, hasMore?: bool}
     */
    public function getCompletionSuggestions(string $argumentName, mixed $currentValue, array $allCurrentArguments = []): array
    {
        // $allCurrentArguments is unused in this mock but part of the signature.
        return $this->suggestionsToReturn;
    }
}
