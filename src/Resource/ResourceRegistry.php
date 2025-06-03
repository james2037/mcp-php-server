<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

use MCP\Server\Registry\Registry;
use MCP\Server\Resource\Attribute\ResourceUri;
use ReflectionClass;

/**
 * Manages the discovery and registration of Resource instances.
 * Resources are typically discovered from classes annotated with ResourceUri.
 */
class ResourceRegistry extends Registry
{
    /**
     * Creates a Resource instance from its reflection class.
     * It expects the class to have a ResourceUri attribute and a constructor
     * compatible with `Resource::__construct`. The resource name is derived
     * from the class's short name.
     *
     * @param ReflectionClass<Resource> $reflection The reflection class of the Resource.
     * @param array<string, mixed> $config Optional configuration for the Resource.
     * @return Resource|null The created Resource, or null if it cannot be created.
     */
    protected function createFromReflection(ReflectionClass $reflection, array $config = []): ?Resource
    {
        $resourceAttr = $reflection->getAttributes(ResourceUri::class)[0] ?? null;
        if ($resourceAttr !== null) {
            // Derive the name from the short class name
            $resourceName = $reflection->getShortName();
            // Instantiate with name first, then nulls for optional params, then config
            // This assumes a constructor like: __construct(string $name, ?string $mimeType, ?int $size, ?Annotations $annotations, ?array $config)
            $resource = $reflection->newInstance($resourceName, null, null, null, $config);
            if ($resource instanceof Resource) {
                return $resource;
            }
        }
        return null;
    }

    /**
     * Gets the URI of the Resource as its unique key.
     *
     * @param object $item The item, which must be a Resource instance.
     * @return string The URI of the Resource.
     * @throws \InvalidArgumentException If the item is not a Resource instance.
     */
    protected function getItemKey(object $item): string
    {
        if (!$item instanceof Resource) {
            throw new \InvalidArgumentException('Item must be an instance of ' . Resource::class);
        }
        return $item->getUri();
    }

    /**
     * Retrieves all registered resources.
     *
     * @return array<string, Resource> An associative array of resources, keyed by their URIs.
     */
    public function getResources(): array
    {
        /** @var array<string, Resource> $items */
        $items = $this->getItems();
        return $items;
    }
}
