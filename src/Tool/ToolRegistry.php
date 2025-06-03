<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Registry\Registry;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use ReflectionClass;

/**
 * Manages the discovery and registration of Tool instances.
 * Tools are typically discovered from classes annotated with the Tool attribute.
 * This registry uses the tool's name as the key for registration.
 */
class ToolRegistry extends Registry
{
    /**
     * Creates a Tool instance from its ReflectionClass object.
     * It expects the class to be a subclass of Tool and have a ToolAttribute.
     *
     * @param ReflectionClass<Tool> $reflection The reflection object for the tool class.
     * @param array<string, mixed> $config Optional configuration array to pass to the tool's constructor.
     * @return Tool|null The created Tool instance, or null if the class is not a valid tool
     *                   (e.g., missing ToolAttribute or not an instance of Tool).
     */
    protected function createFromReflection(
        ReflectionClass $reflection,
        array $config = []
    ): ?Tool {
        $toolAttr = $reflection->getAttributes(ToolAttribute::class)[0] ?? null;
        if ($toolAttr !== null) {
            // Ensure the class is a subclass of Tool before instantiation
            if (!$reflection->isSubclassOf(Tool::class) && $reflection->getName() !== Tool::class) {
                // Optionally log this issue or handle as an error
                return null;
            }
            $toolInstance = $reflection->newInstanceArgs([$config]); // Pass config to constructor
            if ($toolInstance instanceof Tool) {
                return $toolInstance;
            }
        }
        return null;
    }

    /**
     * Gets the name of the Tool as its unique key for registration.
     *
     * @param object $item The item, which must be an instance of Tool.
     * @return string The name of the Tool.
     * @throws \InvalidArgumentException If the provided item is not an instance of Tool.
     */
    protected function getItemKey(object $item): string
    {
        if (!$item instanceof Tool) {
            throw new \InvalidArgumentException('Item must be an instance of ' . Tool::class);
        }
        return $item->getName();
    }

    /**
     * Retrieves all registered tools.
     *
     * @return array<string, Tool> An associative array of tools, keyed by their names.
     */
    public function getTools(): array
    {
        /** @var array<string, Tool> $items */
        $items = $this->getItems();
        return $items;
    }
}
