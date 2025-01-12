<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Registry\Registry;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use ReflectionClass;

class ToolRegistry extends Registry
{
    protected function createFromReflection(ReflectionClass $reflection): ?Tool
    {
        $toolAttr = $reflection->getAttributes(ToolAttribute::class)[0] ?? null;
        if ($toolAttr !== null) {
            $tool = new ($reflection->getName());
            if ($tool instanceof Tool) {
                return $tool;
            }
        }
        return null;
    }

    protected function getItemKey(object $item): string
    {
        if (!$item instanceof Tool) {
            throw new \InvalidArgumentException('Item must be a Tool');
        }
        return $item->getName();
    }

    /**
     * @return array<string, Tool>
     */
    public function getTools(): array
    {
        return $this->getItems();
    }
}
