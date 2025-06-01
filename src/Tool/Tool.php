<?php

/**
 * This file contains the Tool class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content; // Import the namespace
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Abstract base class for tools.
 */
abstract class Tool
{
    private ?ToolAttribute $metadata = null;
    /** @var ?array<string, string|bool> $toolAnnotationsData */
    private ?array $toolAnnotationsData = null;
    /** @var array<string, ParameterAttribute> $parameters */
    private array $parameters = [];
    /** @var array<string, mixed> $config */
    protected array $config = [];

    /**
     * Constructs a new Tool instance.
     *
     * @param array<string, mixed>|null $config Optional configuration for the tool.
     */
    public function __construct(?array $config = null)
    {
        if (!is_null($config)) {
            $this->config = $config;
        }

        $this->initializeMetadata();
    }

    /**
     * Initializes metadata for the tool using reflection.
     */
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
        $toolAnnotationsAttribute =
            $reflection->getAttributes(ToolAnnotations::class);
        if (!empty($toolAnnotationsAttribute)) {
            $toolAnnotationsInstance = $toolAnnotationsAttribute[0]->newInstance();
            $annotationsData = [];
            if ($toolAnnotationsInstance->title !== null) {
                $annotationsData['title'] = $toolAnnotationsInstance->title;
            }
            if ($toolAnnotationsInstance->readOnlyHint !== null) {
                $annotationsData['readOnlyHint'] =
                    $toolAnnotationsInstance->readOnlyHint;
            }
            if ($toolAnnotationsInstance->destructiveHint !== null) {
                $annotationsData['destructiveHint'] =
                    $toolAnnotationsInstance->destructiveHint;
            }
            if ($toolAnnotationsInstance->idempotentHint !== null) {
                $annotationsData['idempotentHint'] =
                    $toolAnnotationsInstance->idempotentHint;
            }
            if ($toolAnnotationsInstance->openWorldHint !== null) {
                $annotationsData['openWorldHint'] =
                    $toolAnnotationsInstance->openWorldHint;
            }

            if (!empty($annotationsData)) {
                $this->toolAnnotationsData = $annotationsData;
            }
        }
    }

    /**
     * Gets the name of the tool.
     *
     * @return string The name of the tool.
     */
    public function getName(): string
    {
        return $this->metadata?->name ?? static::class;
    }

    /**
     * Gets the description of the tool.
     *
     * @return string|null The description of the tool.
     */
    public function getDescription(): ?string
    {
        return $this->metadata?->description;
    }

    /**
     * Gets the annotations of the tool.
     *
     * @return array|null The annotations of the tool.
     */
    public function getAnnotations(): ?array
    {
        return $this->toolAnnotationsData;
    }

    /**
     * Gets the input schema for the tool.
     *
     * @return array The JSON schema for the tool's input.
     */
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

    /**
     * Initializes the tool.
     * Can be overridden by subclasses to perform setup tasks.
     */
    public function initialize(): void
    {
    }

    /**
     * Shuts down the tool.
     * Can be overridden by subclasses to perform cleanup tasks.
     */
    public function shutdown(): void
    {
    }

    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return array<int, array<string, mixed>> An array of content items representing the tool's output.
     * @throws \InvalidArgumentException If arguments are invalid.
     * @throws \LogicException If doExecute returns invalid content.
     */
    final public function execute(array $arguments): array
    {
        $this->validateArguments($arguments);
        $contentItems = $this->doExecute($arguments);
        $resultArray = [];
        foreach ($contentItems as $item) {
            if (!$item instanceof Content\ContentItemInterface) {
                // Or throw an exception, depending on how strict we want to be
                // For now, let's assume doExecute correctly returns
                // ContentItemInterface objects and skip invalid items if any
                // for robustness.
                // A stricter approach might be:
                // throw new \LogicException(
                // 'doExecute must return an array of ContentItemInterface objects.'
                // );
                continue;
            }
            $resultArray[] = $item->toArray();
        }
        return $resultArray;
    }

    /**
     * Validates the arguments for the tool.
     *
     * @param array<string, mixed> $arguments The arguments to validate.
     * @throws \InvalidArgumentException If arguments are invalid.
     */
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
                throw new \InvalidArgumentException(
                    "Missing required argument: {$name}"
                );
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

    /**
     * Validates the type of a value.
     *
     * @param mixed $value The value to validate.
     * @param string $type The expected type.
     * @return bool True if the value is of the expected type, false otherwise.
     */
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
     * Executes the core logic of the tool.
     *
     * This method must be implemented by concrete tool classes. It receives
     * validated arguments and should return an array of ContentItemInterface
     * objects representing the tool's output.
     *
     * @param array<string,mixed> $arguments Validated arguments for the tool.
     * @return Content\ContentItemInterface[] An array of content items
     *                                        representing the tool's response.
     */
    abstract protected function doExecute(array $arguments): array;

    // New Content Creation Helper Methods

    /**
     * Creates a new TextContent item.
     *
     * @param string $text The text content.
     * @param Content\Annotations|null $annotations Optional annotations.
     * @return Content\TextContent The created TextContent item.
     */
    final protected function createTextContent(
        string $text,
        ?Content\Annotations $annotations = null
    ): Content\TextContent {
        return new Content\TextContent($text, $annotations);
    }

    /**
     * Creates a new ImageContent item from raw image data.
     *
     * @param string $rawData The raw image data.
     * @param string $mimeType The MIME type of the image.
     * @param Content\Annotations|null $annotations Optional annotations.
     * @return Content\ImageContent The created ImageContent item.
     */
    final protected function createImageContent(
        string $rawData,
        string $mimeType,
        ?Content\Annotations $annotations = null
    ): Content\ImageContent {
        return new Content\ImageContent(
            base64_encode($rawData),
            $mimeType,
            $annotations
        );
    }

    /**
     * Creates a new AudioContent item from raw audio data.
     *
     * @param string $rawData The raw audio data.
     * @param string $mimeType The MIME type of the audio.
     * @param Content\Annotations|null $annotations Optional annotations.
     * @return Content\AudioContent The created AudioContent item.
     */
    final protected function createAudioContent(
        string $rawData,
        string $mimeType,
        ?Content\Annotations $annotations = null
    ): Content\AudioContent {
        return new Content\AudioContent(
            base64_encode($rawData),
            $mimeType,
            $annotations
        );
    }

    /**
     * Creates a new EmbeddedResource item.
     *
     * @param array $resourceData The resource data.
     * @param Content\Annotations|null $annotations Optional annotations.
     * @return Content\EmbeddedResource The created EmbeddedResource item.
     */
    final protected function createEmbeddedResource(
        array $resourceData,
        ?Content\Annotations $annotations = null
    ): Content\EmbeddedResource {
        return new Content\EmbeddedResource($resourceData, $annotations);
    }

    /**
     * Provides completion suggestions for an argument.
     *
     * Subclasses should override this method to provide actual suggestions.
     * Example: `['values' => ['suggestion1', 'suggestion2'], 'total' => 2, 'hasMore' => false]`
     *
     * @param string $argumentName The name of the argument being completed.
     * @param mixed  $currentValue The current partial value of the argument.
     * @param array  $allArguments All arguments provided so far.
     * @return array{values: string[], total?: int, hasMore?: bool}
     *               An array matching the 'completion' object structure.
     */
    public function getCompletionSuggestions(
        string $argumentName,
        mixed $currentValue,
        array $allArguments = []
    ): array {
        // Default implementation: no suggestions
        return ['values' => [], 'total' => 0, 'hasMore' => false];
    }
}
