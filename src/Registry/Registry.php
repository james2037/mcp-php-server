<?php

declare(strict_types=1);

namespace MCP\Server\Registry;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

abstract class Registry
{
    private array $items = [];

    public function discover(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            include_once $file->getPathname();
            $className = $this->getClassFromFile($file);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            if ($item = $this->createFromReflection($reflection)) {
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

    private function getClassFromFile(\SplFileInfo $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+([^\s{]+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    abstract protected function createFromReflection(ReflectionClass $reflection): ?object;
    abstract protected function getItemKey(object $item): string;
}
