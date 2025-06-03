<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Content\TextContent;

#[ToolAttribute(name: 'google_search', description: 'Performs a Google search and returns the results.')]
class GoogleSearchTool extends Tool
{
    /**
     * Implements the specific logic for this tool using typed, attributed parameters.
     * This method is called by doExecute after arguments are mapped.
     *
     * @param string $query The search query.
     * @param int $max_results The maximum number of results to return.
     * @return TextContent[] Mock search results.
     */
    protected function executeTool(
        #[Parameter(name: 'query', description: 'The search query.', type: 'string', required: true)]
        string $query,
        #[Parameter(name: 'max_results', description: 'The maximum number of results to return.', type: 'integer', required: false)]
        int $max_results = 5
    ): array {
        $results = [];
        // Clamping negative max_results, as schema doesn't enforce minimum.
        if ($max_results < 0) {
            $max_results = 0;
        }

        for ($i = 1; $i <= $max_results; $i++) {
            $title = "Search Result {$i} for '{$query}'";
            $link = "https://www.google.com/search?q=" . urlencode($query) . "&result={$i}";
            $snippet = "This is snippet {$i} for the search query '{$query}'.";
            $results[] = $this->createTextContent(
                "Title: {$title}\nLink: {$link}\nSnippet: {$snippet}"
            );
        }
        return $results;
    }

    /**
     * Executes the tool with the given arguments.
     * This method is required by the base Tool class. It maps the array arguments
     * to the typed parameters of executeTool.
     *
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return TextContent[] An array of content items representing the tool's output.
     */
    protected function doExecute(array $arguments): array
    {
        $query = $arguments['query'] ?? null; // Default handled by executeTool if not required
        $max_results = $arguments['max_results'] ?? 5; // Default from executeTool signature

        // Ensure required 'query' is present (base validateArguments should also check this)
        if ($query === null) {
             // This should ideally be caught by validateArguments if 'query' is required true
             throw new \InvalidArgumentException("Missing required argument: query");
        }

        // Type casting for $max_results if it comes from $arguments array as string, e.g. from JSON
        if (isset($arguments['max_results']) && !is_int($arguments['max_results'])) {
            $max_results_candidate = filter_var($arguments['max_results'], FILTER_VALIDATE_INT);
            if ($max_results_candidate !== false) {
                $max_results = $max_results_candidate;
            } else {
                // If not a valid integer, let executeTool's type hint or default catch it,
                // or throw here if strict parsing is required before calling executeTool.
                // For this example, we rely on executeTool's parameter type `int`.
                // If $arguments['max_results'] was, e.g., "abc", it would cause a TypeError there.
                // The base Tool::validateArguments should also be checking types based on schema.
            }
        }


        return $this->executeTool(
            (string)$query, // Cast to string to be safe, though validateArguments should ensure it
            (int)$max_results // Cast to int
        );
    }
}
