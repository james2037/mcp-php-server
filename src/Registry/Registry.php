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
        $filePath = $file->getRealPath();
        if ($filePath === false) {
            return ''; // File path could not be resolved.
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return ''; // Or throw specific exception
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $className = '';
        $lookingForNamespace = false;
        $lookingForClass = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_NAMESPACE:
                        $lookingForNamespace = true;
                        $lookingForClass = false; // Reset class search if namespace is redefined (though unusual)
                        $namespace = ''; // Reset namespace
                        break;
                    case T_CLASS:
                    case T_INTERFACE:
                    case T_ENUM:
                        if (!$lookingForNamespace) { // Class/Interface/Enum found before namespace or in global namespace
                            $lookingForClass = true;
                        }
                        // If we were looking for namespace parts, finding a class keyword means namespace definition is over.
                        // However, class could be part of a namespace name if not careful.
                        // The T_NAME_QUALIFIED and T_STRING checks handle this.
                        break;
                    case T_STRING: // T_NAME_QUALIFIED for PHP 8+ qualified names, T_STRING for simple names
                    case T_NAME_QUALIFIED:
                        if ($lookingForNamespace) {
                            $namespace .= $token[1];
                        } elseif ($lookingForClass) {
                            $className = $token[1];
                            $lookingForClass = false; // Found the class name, stop looking for it.
                                                     // We take the first one found as per original logic.
                        }
                        break;
                    case T_WHITESPACE:
                        // Ignore whitespace, but it can delimit parts of a namespace.
                        // If building namespace and see whitespace, then next T_STRING is part of it.
                        // If $namespace is not empty and last char is not \\, append \\ if next is T_STRING.
                        if ($lookingForNamespace && !empty($namespace) && $namespace[strlen($namespace) - 1] !== '\\') {
                             // This logic might be too simple for complex namespace names with whitespace.
                             // Usually, T_NAME_QUALIFIED handles multi-part names.
                        }
                        break;
                    // Other tokens like T_NS_SEPARATOR are handled by T_NAME_QUALIFIED or within T_STRING if it's a single backslash.
                }
            } elseif (is_string($token)) {
                // Handle string tokens like ';', '{', '}' etc.
                if ($token === ';' || $token === '{') {
                    if ($lookingForNamespace) {
                        $lookingForNamespace = false; // End of namespace statement
                        $lookingForClass = true; // Start looking for class/interface/enum name
                    }
                }
            }
        }

        if (empty($className)) {
            return ''; // No class, interface, or enum name found
        }

        // Trim leading/trailing backslashes from namespace if any, then ensure single backslash separator.
        $namespace = trim($namespace, '\\');

        return $namespace ? $namespace . '\\' . $className : $className;
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
