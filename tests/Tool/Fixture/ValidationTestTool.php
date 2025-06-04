<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Fixture;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Parameter;
use MCP\Server\Tool\Content\ContentItemInterface;

class ValidationTestTool extends Tool
{
    #[Parameter(name: 'pInt', type: 'integer', description: 'An integer parameter')]
    #[Parameter(name: 'pObj', type: 'object', description: 'An object parameter')]
    #[Parameter(name: 'pAny', type: 'any', description: 'A parameter of any type')]
    #[Parameter(name: 'pOptInt', type: 'integer', description: 'An optional integer parameter', required: false)]
    #[Parameter(name: 'pOptObj', type: 'object', description: 'An optional object parameter', required: false)]
    #[Parameter(name: 'pOptAny', type: 'any', description: 'An optional parameter of any type', required: false)]
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
