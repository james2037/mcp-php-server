<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

use MCP\Server\Registry\Registry;
use MCP\Server\Resource\Attribute\ResourceUri;
use ReflectionClass;

class ResourceRegistry extends Registry
{
    protected function createFromReflection(ReflectionClass $reflection, array $config = []): ?Resource
    {
        $resourceAttr = $reflection->getAttributes(ResourceUri::class)[0] ?? null;
        if ($resourceAttr !== null) {
            // Derive the name from the short class name
            $resourceName = $reflection->getShortName();
            // Instantiate with name first, then nulls for optional params, then config
            $resource = new ($reflection->getName())($resourceName, null, null, null, $config);
            if ($resource instanceof Resource) {
                return $resource;
            }
        }
        return null;
    }

    protected function getItemKey(object $item): string
    {
        if (!$item instanceof Resource) {
            throw new \InvalidArgumentException('Item must be a Resource');
        }
        return $item->getUri();
    }

    /**
     * @return array<string, Resource>
     */
    public function getResources(): array
    {
        return $this->getItems();
    }
}
