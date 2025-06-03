<?php

namespace MCP\Server\Tests\Tool;

use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Content\TextContent;
use MCP\Server\Tool\Tool;

#[ToolAttribute('array-tool', 'Tests array parameters')]
class ArrayParamTool extends Tool
{
    /**
     * Processes an array of numbers if enabled.
     *
     * @param array<int|float> $numbers List of numbers.
     * @param bool $enabled Whether processing is enabled.
     * @return TextContent[]
     */
    protected function executeTool(
        #[ParameterAttribute('numbers', type: 'array', description: 'List of numbers', required: true)]
        array $numbers,
        #[ParameterAttribute('enabled', type: 'boolean', description: 'Whether processing is enabled', required: true)]
        bool $enabled
    ): array {
        if (!$enabled) {
            return [$this->createTextContent('Processing disabled')];
        }
        // We might want to check if elements of $numbers are numeric here if schema cannot specify item types.
        foreach ($numbers as $num) {
            if (!is_int($num) && !is_float($num)) {
                throw new \InvalidArgumentException("All elements in 'numbers' array must be numeric.");
            }
        }
        // @phpstan-ignore-next-line Confusing "Result of && is always false" from PHPStan. Appears to be a false positive or deep analysis quirk as code is simple and tests pass.
        $sum = array_sum($numbers);
        return [$this->createTextContent("Sum: $sum")];
    }

    protected function doExecute(array $arguments): array
    {
        // Base validateArguments should have ensured 'numbers' and 'enabled' are present and correctly typed.
        return $this->executeTool(
            $arguments['numbers'], // Assumed array
            $arguments['enabled']  // Assumed bool
        );
    }
}
