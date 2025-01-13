<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

abstract class Tool
{
    private ?ToolAttribute $metadata = null;
    private array $parameters = [];
    protected array $config = [];

    public function __construct(?array $config = null)
    {
        if (!is_null($config)) {
            $this->config = $config;
        }

        $this->initializeMetadata();
    }

    private function initializeMetadata(): void
    {
        $reflection = new ReflectionClass($this);

        // Get tool metadata
        $toolAttrs = $reflection->getAttributes(ToolAttribute::class);
        if (count($toolAttrs) > 0) {
            $this->metadata = $toolAttrs[0]->newInstance();
        }

        // Get parameter metadata from doExecute method
        $method = $reflection->getMethod('doExecute');
        foreach ($method->getParameters() as $param) {
            $attrs = $param->getAttributes(ParameterAttribute::class);
            foreach ($attrs as $attr) {
                $paramAttr = $attr->newInstance();
                // Store using the attribute name as key
                $this->parameters[$paramAttr->name] = $paramAttr;
            }
        }
    }

    public function getName(): string
    {
        return $this->metadata?->name ?? static::class;
    }

    public function getDescription(): ?string
    {
        return $this->metadata?->description;
    }

    public function getInputSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $name => $param) {
            $properties[$name] = [
                'type' => $param->type
            ];

            if ($param->description !== null) {
                $properties[$name]['description'] = $param->description;
            }

            if ($param->required) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => !empty($required) ? $required : null
        ];

        return $schema;
    }

    public function initialize(): void
    {
    }

    public function shutdown(): void
    {
    }

    final public function execute(array $arguments): array
    {
        $this->validateArguments($arguments);
        return $this->doExecute($arguments);
    }

    protected function validateArguments(array $arguments): void
    {
        // Check for unknown arguments
        foreach ($arguments as $name => $value) {
            if (!isset($this->parameters[$name])) {
                throw new \InvalidArgumentException("Unknown argument: {$name}");
            }
        }

        // Check required parameters
        foreach ($this->parameters as $name => $param) {
            if ($param->required && !isset($arguments[$name])) {
                throw new \InvalidArgumentException("Missing required argument: {$name}");
            }
        }

        // Check parameter types
        foreach ($arguments as $name => $value) {
            $param = $this->parameters[$name];
            if (!$this->validateType($value, $param->type)) {
                throw new \InvalidArgumentException(
                    "Invalid type for argument {$name}: expected {$param->type}"
                );
            }
        }
    }

    private function validateType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'number' => is_numeric($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            default => true // Allow unknown types
        };
    }

    /**
     * Execute the tool implementation
     *
     * @param  array<string,mixed> $arguments Validated arguments
     * @return array Tool response content
     */
    abstract protected function doExecute(array $arguments): array;

    /**
     * Create a text content response
     */
    protected function text(string $text, ?array $annotations = null): array
    {
        $content = [
            'type' => 'text',
            'text' => $text
        ];

        if ($annotations !== null) {
            $content['annotations'] = $annotations;
        }

        return [$content];
    }

    /**
     * Create an image content response
     */
    protected function image(string $data, string $mimeType, ?array $annotations = null): array
    {
        $content = [
            'type' => 'image',
            'data' => base64_encode($data),
            'mimeType' => $mimeType
        ];

        if ($annotations !== null) {
            $content['annotations'] = $annotations;
        }

        return [$content];
    }

    /**
     * Create a resource content response
     */
    protected function resource(ResourceContents $resource, ?array $annotations = null): array
    {
        $content = [
            'type' => 'resource',
            'resource' => $resource
        ];

        if ($annotations !== null) {
            $content['annotations'] = $annotations;
        }

        return [$content];
    }

    /**
     * Combine multiple content responses
     */
    protected function combine(array ...$contents): array
    {
        return array_merge(...$contents);
    }
}
