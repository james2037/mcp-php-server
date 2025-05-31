<?php

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content; // Import the namespace
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

abstract class Tool
{
    private ?ToolAttribute $metadata = null;
    private ?array $toolAnnotationsData = null;
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

        // Get ToolAnnotations attribute
        $toolAnnotationsAttribute = $reflection->getAttributes(ToolAnnotations::class);
        if (!empty($toolAnnotationsAttribute)) {
            $toolAnnotationsInstance = $toolAnnotationsAttribute[0]->newInstance();
            $annotationsData = [];
            if ($toolAnnotationsInstance->title !== null) {
                $annotationsData['title'] = $toolAnnotationsInstance->title;
            }
            if ($toolAnnotationsInstance->readOnlyHint !== null) {
                $annotationsData['readOnlyHint'] = $toolAnnotationsInstance->readOnlyHint;
            }
            if ($toolAnnotationsInstance->destructiveHint !== null) {
                $annotationsData['destructiveHint'] = $toolAnnotationsInstance->destructiveHint;
            }
            if ($toolAnnotationsInstance->idempotentHint !== null) {
                $annotationsData['idempotentHint'] = $toolAnnotationsInstance->idempotentHint;
            }
            if ($toolAnnotationsInstance->openWorldHint !== null) {
                $annotationsData['openWorldHint'] = $toolAnnotationsInstance->openWorldHint;
            }

            if (!empty($annotationsData)) {
                $this->toolAnnotationsData = $annotationsData;
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

    public function getAnnotations(): ?array
    {
        return $this->toolAnnotationsData;
    }

    public function getInputSchema(): array
    {
        $properties = new \stdClass();
        $required = [];

        foreach ($this->parameters as $name => $param) {
            // Create a property object for each parameter
            $propObj = new \stdClass();
            $propObj->type = $param->type;

            if ($param->description !== null) {
                $propObj->description = $param->description;
            }

            // Assign the property object to the properties object
            $properties->$name = $propObj;

            if ($param->required) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

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
        $contentItems = $this->doExecute($arguments);
        $resultArray = [];
        foreach ($contentItems as $item) {
            if (!$item instanceof Content\ContentItemInterface) {
                // Or throw an exception, depending on how strict we want to be
                // For now, let's assume doExecute correctly returns ContentItemInterface objects
                // and skip invalid items if any for robustness.
                // A stricter approach might be:
                // throw new \LogicException('doExecute must return an array of ContentItemInterface objects.');
                continue;
            }
            $resultArray[] = $item->toArray();
        }
        return $resultArray;
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
     * @return Content\ContentItemInterface[] Tool response content items
     */
    abstract protected function doExecute(array $arguments): array;

    // New Content Creation Helper Methods

    protected final function createTextContent(string $text, ?Content\Annotations $annotations = null): Content\TextContent
    {
        return new Content\TextContent($text, $annotations);
    }

    protected final function createImageContent(string $base64Data, string $mimeType, ?Content\Annotations $annotations = null): Content\ImageContent
    {
        return new Content\ImageContent($base64Data, $mimeType, $annotations);
    }

    protected final function createAudioContent(string $base64Data, string $mimeType, ?Content\Annotations $annotations = null): Content\AudioContent
    {
        return new Content\AudioContent($base64Data, $mimeType, $annotations);
    }

    protected final function createEmbeddedResource(array $resourceData, ?Content\Annotations $annotations = null): Content\EmbeddedResource
    {
        return new Content\EmbeddedResource($resourceData, $annotations);
    }
}
