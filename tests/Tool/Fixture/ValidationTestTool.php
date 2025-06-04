<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Fixture;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Tool\Content\ContentItemInterface;

class ValidationTestTool extends Tool
{
    protected function doExecute(array $arguments): ContentItemInterface
    {
        $results = [];
        // Iterate over expected keys to ensure consistent logging for test assertions
        $expectedKeys = ['pInt', 'pObj', 'pAny', 'pOptInt', 'pOptObj', 'pOptAny'];
        foreach ($expectedKeys as $key) {
            if (array_key_exists($key, $arguments)) {
                $results[] = "{$key} type: " . gettype($arguments[$key]);
            }
        }
        return $this->text(implode(', ', $results));
    }
}
