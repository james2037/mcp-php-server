<?php

declare(strict_types=1);

// Blank line after declare, before namespace, as per PSR-12
namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\GoogleSearchTool;
use MCP\Server\Tool\Content\TextContent;
use PHPUnit\Framework\TestCase;

class GoogleSearchToolTest extends TestCase
{
    public function testSearchReturnsMockResultsAsTextContent(): void
    {
        $tool = new GoogleSearchTool();
        $query = 'test query';
        // Call execute() which internally calls doExecute()
        $results = $tool->execute(['query' => $query]);

        $this->assertIsArray($results);
        $this->assertCount(5, $results, 'Should return 5 results by default');

        foreach ($results as $i => $result) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('type', $result);
            $this->assertEquals('text', $result['type']); // Changed from TextContent::TYPE
            $this->assertArrayHasKey('text', $result);
            $expectedText = "Title: Search Result " . ($i + 1) . " for '{$query}'\n" .
                            "Link: https://www.google.com/search?q=" . urlencode($query) . "&result=" . ($i + 1) . "\n" .
                            "Snippet: This is snippet " . ($i + 1) . " for the search query '{$query}'.";
            $this->assertEquals($expectedText, $result['text']);
        }
    }

    public function testSearchWithMaxResultsParameterAsTextContent(): void
    {
        $tool = new GoogleSearchTool();
        $query = 'another query';
        $maxResults = 3;
        $results = $tool->execute(['query' => $query, 'max_results' => $maxResults]);

        $this->assertIsArray($results);
        $this->assertCount($maxResults, $results, "Should return {$maxResults} results");

        foreach ($results as $i => $result) {
            $this->assertIsArray($result);
            $this->assertEquals('text', $result['type']); // Changed from TextContent::TYPE
            $this->assertStringContainsString("Search Result " . ($i + 1) . " for '{$query}'", $result['text']);
        }
    }

    public function testSearchWithZeroMaxResultsAsTextContent(): void
    {
        $tool = new GoogleSearchTool();
        $query = 'zero results query';
        $maxResults = 0;
        $results = $tool->execute(['query' => $query, 'max_results' => $maxResults]);

        $this->assertIsArray($results);
        $this->assertCount($maxResults, $results, "Should return 0 results");
    }

    public function testSearchWithNegativeMaxResultsDefaultsToZero(): void
    {
        $tool = new GoogleSearchTool();
        $query = 'negative results query';
        $maxResults = -5; // Test with negative max_results
        // The tool's doExecute method should handle negative max_results (e.g., by treating as 0)
        $results = $tool->execute(['query' => $query, 'max_results' => $maxResults]);

        $this->assertIsArray($results);
        $this->assertCount(0, $results, "Should return 0 results if max_results is negative");
    }

    public function testMissingQueryArgumentThrowsException(): void
    {
        $tool = new GoogleSearchTool();
        $this->expectException(\InvalidArgumentException::class);
        // The base Tool class's execute method should throw an exception due to missing required 'query'
        $tool->execute([]);
    }
}
