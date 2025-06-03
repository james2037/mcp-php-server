<?php

/**
 * This file contains the Tool class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool;

use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use MCP\Server\Tool\Attribute\ToolAnnotations;
use MCP\Server\Tool\Content;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Abstract base class for server tools.
 *
 * Tools are self-contained units of functionality that can be executed by the server.
 * They define their metadata (name, description, parameters, annotations) using PHP attributes
 * (Tool, Parameter, ToolAnnotations). The `initializeMetadata` method reads these attributes
 * using reflection.
 *
 * Subclasses must implement the `doExecute` method, which contains the core logic of the tool.
 * Helper methods are provided for creating standard content item types (text, image, audio, resource).
 * Tools also have `initialize` and `shutdown` lifecycle methods that can be overridden.
 */
abstract class Tool
{
    /** @var ToolAttribute|null Metadata extracted from the Tool attribute (name, description). */
    private ?ToolAttribute $metadata = null;
    /** @var array<string, string|bool>|null Annotations extracted from the ToolAnnotations attribute. */
    private ?array $toolAnnotationsData = null;
    /** @var array<string, ParameterAttribute> Parameters extracted from Parameter attributes on the doExecute() method, keyed by parameter name. */
    private array $parameters = [];
    /** @var array<string, mixed> Configuration for the tool, passed during construction. */
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
     * This method reads Tool, Parameter (from doExecute), and ToolAnnotations attributes
     * to populate the tool's metadata properties.
     */
    private function initializeMetadata(): void
    {
        $reflection = new ReflectionClass($this);

        $toolAttrs = $reflection->getAttributes(ToolAttribute::class);
        if (count($toolAttrs) > 0) {
            $this->metadata = $toolAttrs[0]->newInstance();
        }

        if ($reflection->hasMethod('doExecute')) {
            $method = $reflection->getMethod('doExecute');
            foreach ($method->getParameters() as $param) {
                $attrs = $param->getAttributes(ParameterAttribute::class);
                foreach ($attrs as $attr) {
                    $paramAttr = $attr->newInstance();
                    $this->parameters[$paramAttr->name] = $paramAttr;
                }
            }
        }

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

    /**
     * Gets the name of the tool.
     * Falls back to the class name if the Tool attribute is not present.
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
            $propObj = new \stdClass();
            $propObj->type = $param->type; // Type from Parameter attribute

            if ($param->description !== null) {
                $propObj->description = $param->description; // Description from Parameter attribute
            }

            // TODO: Handle other schema properties like 'enum', 'default', 'items' (for array type) if needed.
            // For now, it's basic type and description.

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
        foreach ($arguments as $name => $value) {
            if (!isset($this->parameters[$name])) {
                throw new \InvalidArgumentException("Unknown argument: {$name}");
            }
        }

        foreach ($this->parameters as $name => $param) {
            if ($param->required && !isset($arguments[$name])) {
                throw new \InvalidArgumentException("Missing required argument: {$name}");
            }
            // If parameter is present, validate its type.
            // If not required and not present, skip type validation.
            if (isset($arguments[$name])) {
                if (!$this->validateType($arguments[$name], $param->type)) {
                    throw new \InvalidArgumentException(
                        "Invalid type for argument {$name}: expected {$param->type}, got " . gettype($arguments[$name])
                    );
                }
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
            // For 'any' or other custom types, we might need more sophisticated validation or assume true.
            // 'any' type essentially means no type validation beyond presence if required.
            'any' => true, // Explicitly allow 'any' type.
            default => true // Allow other unknown types by default, or could be stricter.
        };
    }

    /**
     * Executes the core logic of the tool.
     *
     * This method must be implemented by concrete tool classes. It receives
     * validated arguments and should return an array of ContentItemInterface
     * objects representing the tool's output.
     *
     * @param array<string,mixed> $arguments Validated arguments for the tool, matching the defined parameters.
     * @return Content\ContentItemInterface[] An array of content items (e.g., TextContent, ImageContent)
     *                                        representing the tool's response.
     */
    abstract protected function doExecute(array $arguments): array;

    // Content Creation Helper Methods

    /**
     * Creates a new TextContent item.
     * This is a convenience method for subclasses to easily create text outputs.
     *
     * @param string $text The text content.
     * @param Content\Annotations|null $annotations Optional annotations for the text content.
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
     * The raw data will be base64 encoded.
     * This is a convenience method for subclasses.
     *
     * @param string $rawData The raw binary image data.
     * @param string $mimeType The MIME type of the image (e.g., "image/png", "image/jpeg").
     * @param Content\Annotations|null $annotations Optional annotations for the image content.
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
     * The raw data will be base64 encoded.
     * This is a convenience method for subclasses.
     *
     * @param string $rawData The raw binary audio data.
     * @param string $mimeType The MIME type of the audio (e.g., "audio/mpeg", "audio/wav").
     * @param Content\Annotations|null $annotations Optional annotations for the audio content.
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
     * This is a convenience method for subclasses to embed resource data directly in the output.
     *
     * @param array $resourceData The resource data, conforming to TextResourceContents or BlobResourceContents structure.
     *                            Example: `['uri' => '/my/data', 'text' => 'hello', 'mimeType' => 'text/plain']`
     * @param Content\Annotations|null $annotations Optional annotations for the embedded resource.
     * @return Content\EmbeddedResource The created EmbeddedResource item.
     */
    final protected function createEmbeddedResource(
        array $resourceData,
        ?Content\Annotations $annotations = null
    ): Content\EmbeddedResource {
        return new Content\EmbeddedResource($resourceData, $annotations);
    }

    /**
     * Provides completion suggestions for a tool argument based on the current input.
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
