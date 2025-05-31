<?php

declare(strict_types=1);

namespace MCP\Server\Registry;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

abstract class Registry
{
    private array $items = [];

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
            $className = $this->getClassFromFile($file); // Use $this->

            if (!$className || !class_exists($className)) { // Check if $className is valid
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

    protected function getItems(): array
    {
        return $this->items;
    }

    public function register(object $item): void
    {
        $this->items[$this->getItemKey($item)] = $item;
    }

    // Changed from private to protected to allow potential child class access if needed,
    // or keep as private if strictly internal. For now, private is fine as per original.
    // Renamed from _getClassFromFile
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

    abstract protected function createFromReflection(ReflectionClass $reflection, array $config): ?object;
    abstract protected function getItemKey(object $item): string;
}
