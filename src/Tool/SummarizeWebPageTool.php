<?php

declare(strict_types=1);

// Blank line after declare, before namespace, as per PSR-12
namespace MCP\Server\Tool;

// Blank line after namespace, before use, as per PSR-12
use MCP\Server\Tool\Attribute\Parameter; // Though not used directly on doExecute, useful for constructor/schema
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute(name: 'summarize_web_page', description: 'Summarizes the content of a web page.')]
class SummarizeWebPageTool extends Tool
{
    /**
     * Implements the specific logic for this tool using typed, attributed parameters.
     *
     * @param string $url The URL of the web page to summarize.
     * @param int $max_words The maximum number of words for the summary.
     * @return TextContent[] The summary as a TextContent item.
     */
    protected function executeTool(
        #[Parameter(name: 'url', description: 'The URL of the web page to summarize.', type: 'string', required: true)]
        string $url,
        #[Parameter(name: 'max_words', description: 'The maximum number of words for the summary.', type: 'integer', required: false)]
        int $max_words = 100
    ): array {
        // Specific validation for URL format beyond just being a string.
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid URL format provided: {$url}");
        }

        // Ensure max_words is positive.
        if ($max_words <= 0) {
            $max_words = 1; // Default to 1 if non-positive value provided.
        }

        // Simulate fetching web page content.
        // In a real scenario, this might use an HTTP client.
        // The prompt mentions using `view_text_website` tool, which is an agent tool,
        // not directly callable from PHP code here. So, mocking this part.
        $mockContent = "This is mock content for the URL: {$url}. It is long enough to require summarization. " .
                       "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut " .
                       "labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco " .
                       "laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in " .
                       "voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat " .
                       "non proident, sunt in culpa qui officia deserunt mollit anim id est laborum. " .
                       "Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, " .
                       "totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae " .
                       "dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, " .
                       "sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, " .
                       "qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi " .
                       "tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem.";

        // Simulate summarization (simple truncation by words)
        $words = preg_split('/\s+/', $mockContent);

        if ($words === false) {
            // preg_split failed, return empty summary or error
            return [$this->createTextContent("Error: Could not process content for summarization.")];
        }

        if (count($words) > $max_words) {
            $summary = implode(' ', array_slice($words, 0, $max_words)) . '...';
        } else {
            $summary = $mockContent;
        }

        return [$this->createTextContent($summary)];
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
        $url = $arguments['url'] ?? null;
        $max_words = $arguments['max_words'] ?? 100; // Default from executeTool signature

        if ($url === null) {
            throw new \InvalidArgumentException("Missing required argument: url");
        }

        // Type casting for $max_words if it comes from $arguments array as string
        if (isset($arguments['max_words']) && !is_int($arguments['max_words'])) {
             $max_words_candidate = filter_var($arguments['max_words'], FILTER_VALIDATE_INT);
            if ($max_words_candidate !== false) {
                $max_words = $max_words_candidate;
            }
            // Rely on executeTool's type hint for int if filter_var fails
        }

        return $this->executeTool((string)$url, (int)$max_words);
    }
}
