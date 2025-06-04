<?php

declare(strict_types=1);

namespace MCP\Server\Registry;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Abstract base class for discovering and registering items (like Tools or Resources)
 * from files within a specified directory.
 */
abstract class Registry
{
    /** @var array<string, object> Holds the registered items, keyed by a unique string. */
    private array $items = [];

    /**
     * Discovers and registers items from PHP files in the given directory.
     * It iterates through PHP files, attempts to find a class name,
     * and uses the abstract `createFromReflection` method to instantiate items.
     *
     * @param string $directory The directory to scan for PHP files.
     * @param array<string, mixed> $config Optional configuration to pass to `createFromReflection`.
     */
    public function discover(string $directory, array $config = []): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Ensure the file is included before trying to get class info
            // This was include_once, which is fine.
            include_once $file->getPathname();
            $className = $this->getClassFromFile($file);

            if (!$className || !class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            if ($item = $this->createFromReflection($reflection, $config)) {
                $this->register($item);
            }
        }
    }

    /**
     * Returns all registered items.
     *
     * @return array<string, object> An array of registered items.
     */
    protected function getItems(): array
    {
        return $this->items;
    }

    /**
     * Registers an item with the registry.
     * The item is keyed using the `getItemKey` method.
     *
     * @param object $item The item to register.
     */
    public function register(object $item): void
    {
        $this->items[$this->getItemKey($item)] = $item;
    }

    /**
     * Extracts the fully qualified class name from a PHP file.
     * Note: This method uses regular expressions and may not cover all edge cases
     * of PHP syntax. It assumes one class per file for simplicity.
     *
     * @param \SplFileInfo $file The file information object.
     * @return string The fully qualified class name, or an empty string if not found.
     */
    private function getClassFromFile(\SplFileInfo $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            return ''; // Or throw exception
        }

        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+([^\s{]+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if (empty($class)) {
            return ''; // Or throw exception if class name is mandatory
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    /**
     * Creates an item instance from its reflection class.
     * This method must be implemented by subclasses to define how items are instantiated
     * (e.g., Tools, Resources) and configured.
     *
     * @param ReflectionClass<object> $reflection The reflection class of the item to create.
     * @param array<string, mixed> $config Optional configuration data for the item.
     * @return object|null The created item, or null if it should not be registered.
     */
    abstract protected function createFromReflection(ReflectionClass $reflection, array $config): ?object;

    /**
     * Gets a unique key for the given item.
     * This method must be implemented by subclasses to define how items are identified
     * within the registry (e.g., by name, URI).
     *
     * @param object $item The item for which to get the key.
     * @return string The unique key for the item.
     */
    abstract protected function getItemKey(object $item): string;
}
