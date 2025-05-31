<?php

/**
 * This file contains the ToolRegistry class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Registry\Registry;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use ReflectionClass;

/**
 * A registry for tools.
 */
class ToolRegistry extends Registry
{
    /**
     * Creates a Tool instance from a ReflectionClass.
     *
     * @param ReflectionClass $reflection The reflection class.
     * @param array $config Optional configuration for the tool.
     * @return Tool|null The Tool instance or null if creation fails.
     */
    protected function createFromReflection(
        ReflectionClass $reflection,
        array $config = []
    ): ?Tool {
        $toolAttr = $reflection->getAttributes(ToolAttribute::class)[0] ?? null;
        if ($toolAttr !== null) {
            $tool = new ($reflection->getName())($config);
            if ($tool instanceof Tool) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Gets the key for a given item.
     *
     * @param object $item The item.
     * @return string The key for the item.
     * @throws \InvalidArgumentException If the item is not a Tool.
     */
    protected function getItemKey(object $item): string
    {
        if (!$item instanceof Tool) {
            throw new \InvalidArgumentException('Item must be a Tool');
        }
        return $item->getName();
    }

    /**
     * Gets all registered tools.
     *
     * @return array<string, Tool>
     */
    public function getTools(): array
    {
        return $this->getItems();
    }
}
