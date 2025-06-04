<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Registry\Helpers;

use MCP\Server\Registry\Registry;
use ReflectionClass;

/**
 * Concrete implementation of Registry for testing purposes.
 */
class ConcreteTestRegistry extends Registry
{
    /**
     * Attempts to create an instance of the class via reflection.
     * For testing, it primarily targets classes that can be instantiated without constructor arguments.
     *
     * @param ReflectionClass<object> $reflection The reflection object for the class.
     * @param array<string, mixed> $config Optional configuration (not used in this basic version).
     * @return object|null An instance of the class, or null if it cannot be instantiated or is abstract.
     */
    protected function createFromReflection(ReflectionClass $reflection, array $config = []): ?object
    {
        if ($reflection->isAbstract()) {
            return null;
        }

        // Only try to instantiate if the class has no mandatory constructor parameters
        // or if it's one of our specific test discoverable classes.
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            try {
                return $reflection->newInstance();
            } catch (\Throwable $e) {
                // Could not instantiate (e.g., internal error in constructor)
                return null;
            }
        } elseif (
            $reflection->getName() === DiscoverableTestClassForRegistry::class ||
            $reflection->getName() === AnotherDiscoverableTestClassForRegistry::class
        ) {
            // These specific classes are known to be instantiable for testing.
            // If they had required constructor args, this would need specific handling.
            try {
                return $reflection->newInstance();
            } catch (\Throwable $e) {
                return null;
            }
        }


        return null; // Cannot instantiate other classes with required constructor parameters easily
    }

    /**
     * Gets a unique key for the given item.
     * For stdClass instances with a 'name' property, uses that. Otherwise, uses the class name.
     *
     * @param object $item The item for which to get the key.
     * @return string The unique key for the item.
     */
    protected function getItemKey(object $item): string
    {
        if ($item instanceof \stdClass && isset($item->name)) {
            return $item->name;
        }
        // For discovered classes, using their name is a common pattern.
        return get_class($item); // Fixed: Use get_class($item) to get the class name of the item.
    }
}
