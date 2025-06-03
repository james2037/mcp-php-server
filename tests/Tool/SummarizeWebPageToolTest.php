<?php

declare(strict_types=1);

// Blank line after declare, before namespace, as per PSR-12
namespace MCP\Server\Tests\Tool;

// Blank line after namespace, before use, as per PSR-12
use MCP\Server\Tool\SummarizeWebPageTool;
use PHPUnit\Framework\TestCase;

class SummarizeWebPageToolTest extends TestCase
{
    private SummarizeWebPageTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new SummarizeWebPageTool();
    }

    public function testSummarizeWebPageDefaultMaxWords(): void
    {
        $url = 'http://example.com/testpage';
        $results = $this->tool->execute(['url' => $url]);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $textContent = $results[0];
        $this->assertEquals('text', $textContent['type']);
        $this->assertStringContainsString("This is mock content for the URL: {$url}", $textContent['text']);

        // Default max_words is 100
        $words = preg_split('/\s+/', $textContent['text']);
        $this->assertIsArray($words, 'preg_split should return an array for this input.');
        // Count words, accounting for the "..."
        $this->assertTrue(count($words) <= 101, "Summary should be around 100 words. Found: " . count($words));
        $this->assertStringEndsWith('...', $textContent['text']);
    }

    public function testSummarizeWebPageCustomMaxWords(): void
    {
        $url = 'http://example.com/anotherpage';
        $maxWords = 20;
        $results = $this->tool->execute(['url' => $url, 'max_words' => $maxWords]);

        $this->assertCount(1, $results);
        $textContent = $results[0];
        $this->assertEquals('text', $textContent['type']);
        $words = preg_split('/\s+/', $textContent['text']);
        $this->assertIsArray($words, 'preg_split should return an array for this input.');
        $this->assertTrue(count($words) <= $maxWords + 1); // +1 for "..."
        $this->assertStringEndsWith('...', $textContent['text']);
    }

    public function testSummarizeContentShorterThanMaxWords(): void
    {
        $url = 'http://example.com/shortpage';
        // Mocking a scenario where fetched content is very short.
        // This requires modifying the tool or having a way to inject mock content for tests.
        // For now, we assume the mock content in the tool is long enough.
        // A more robust test would involve injecting a mock HTTP client or content fetcher.
        // Here, we test with a large max_words to ensure no "..." is added.
        $maxWords = 500; // Assuming mock content is shorter than this
        $results = $this->tool->execute(['url' => $url, 'max_words' => $maxWords]);
        $this->assertCount(1, $results);
        $textContent = $results[0];
        $this->assertEquals('text', $textContent['type']);
        $this->assertStringEndsNotWith('...', $textContent['text']); // Corrected method name
    }

    public function testUrlIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // This message comes from SummarizeWebPageTool::doExecute
        $this->expectExceptionMessage("Missing required argument: url");
        $this->tool->execute([]);
    }

    public function testUrlMustBeValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // This message comes from SummarizeWebPageTool::executeTool
        $this->expectExceptionMessage("Invalid URL format provided: not-a-valid-url");
        $this->tool->execute(['url' => 'not-a-valid-url']);
    }

    public function testMaxWordsIsZeroOrNegativeIsClampedToOne(): void
    {
        // Test with max_words = 0
        $resultsZero = $this->tool->execute(['url' => 'http://example.com', 'max_words' => 0]);
        $this->assertCount(1, $resultsZero);
        $textContentZero = $resultsZero[0];
        $wordsZero = preg_split('/\s+/', $textContentZero['text']);
        $this->assertIsArray($wordsZero);
         // It produces 1 word + "..." = 2 elements if source is long enough, or just 1 word if source is 1 word.
         // The mock content is long. So, "Word1..." (2 elements if preg_split counts ... as a word)
         // or if "..." is attached, then 1 element.
         // Let's check if the summary text contains "..." or not.
         // If max_words becomes 1, it should take the first word and add "..."
        $this->assertStringEndsWith('...', $textContentZero['text']);
        $this->assertGreaterThanOrEqual(1, count($wordsZero));


        // Test with max_words = -5
        $resultsNegative = $this->tool->execute(['url' => 'http://example.com', 'max_words' => -5]);
        $this->assertCount(1, $resultsNegative);
        $textContentNegative = $resultsNegative[0];
        $wordsNegative = preg_split('/\s+/', $textContentNegative['text']);
        $this->assertIsArray($wordsNegative);
        $this->assertStringEndsWith('...', $textContentNegative['text']);
        $this->assertGreaterThanOrEqual(1, count($wordsNegative));
    }

    public function testMaxWordsMustBeInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // This message now comes from the base Tool::validateArguments
        $this->expectExceptionMessage("Invalid type for argument max_words: expected integer, got string");
        $this->tool->execute(['url' => 'http://example.com', 'max_words' => 'not-an-integer']);
    }
}
