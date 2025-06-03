<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Tool\Content\Annotations;

/**
 * Abstract base class for server resources.
 * Resources are entities that can be listed and read, identified by a URI.
 * They utilize the ResourceUri attribute to define their URI and description.
 */
abstract class Resource
{
    /** Holds the metadata extracted from the ResourceUri attribute, if present. */
    private ?ResourceUri $metadata = null;
    /** @var array<string, mixed> Configuration for the resource. */
    protected array $config = [];

    // New properties to match the Resource schema for listing
    /** The name of the resource. */
    public readonly string $name;
    /** Optional MIME type of the resource. */
    public readonly ?string $mimeType;
    /** Optional size of the resource in bytes. */
    public readonly ?int $size;
    /** Optional annotations for the resource. */
    public readonly ?Annotations $annotations;

    /**
     * Constructs a new Resource instance.
     *
     * @param string $name The name of the resource. This is mandatory for listing.
     * @param string|null $mimeType Optional MIME type of the resource.
     * @param int|null $size Optional size of the resource in bytes.
     * @param Annotations|null $annotations Optional annotations for the resource.
     * @param array<string, mixed>|null $config Optional configuration for the resource.
     *        // TODO: Use @param array<string, mixed>|null $config once syntax is supported
     */
    public function __construct(
        string $name, // Name is now mandatory for a listable resource
        ?string $mimeType = null,
        ?int $size = null,
        ?Annotations $annotations = null,
        ?array $config = null
    ) {
        $this->name = $name;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->annotations = $annotations;

        if (!is_null($config)) {
            $this->config = $config;
        }

        $this->initializeMetadata(); // Reads ResourceUri for URI template and description
    }

    /**
     * Initializes metadata by reading the ResourceUri attribute from the class.
     */
    private function initializeMetadata(): void
    {
        $reflection = new \ReflectionClass($this);
        $attrs = $reflection->getAttributes(ResourceUri::class);
        if (count($attrs) > 0) {
            $this->metadata = $attrs[0]->newInstance();
        }
    }

    /**
     * Gets the URI of the resource.
     * Prefers the URI from the ResourceUri attribute, falls back to the class name.
     *
     * @return string The resource URI.
     */
    public function getUri(): string
    {
        return $this->metadata?->uri ?? static::class;
    }

    /**
     * Gets the description of the resource from the ResourceUri attribute.
     *
     * @return string|null The resource description, or null if not set.
     */
    public function getDescription(): ?string
    {
        return $this->metadata?->description;
    }

    /**
     * Converts the resource to an array representation suitable for listings.
     * Includes URI, name, and optionally description, mimeType, size, and annotations.
     *
     * @return array<string, mixed> The array representation of the resource.
     */
    public function toArray(): array
    {
        $data = [
            'uri' => $this->getUri(), // From ResourceUri attribute
            'name' => $this->name,   // From constructor
        ];
        $description = $this->getDescription(); // From ResourceUri attribute
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        if ($this->size !== null) {
            $data['size'] = $this->size;
        }
        if ($this->annotations !== null) {
            $serializedAnnotations = $this->annotations->toArray();
            if (!empty($serializedAnnotations)) {
                $data['annotations'] = $serializedAnnotations;
            }
        }
        return $data;
    }

    /**
     * Reads the content of the resource.
     * Subclasses must implement this method to provide the actual resource content.
     *
     * @param array<string, string|int|float|bool> $parameters Parameters extracted from the URI or provided by the client.
     * @return ResourceContents The contents of the resource.
     */
    abstract public function read(array $parameters = []): ResourceContents;

    /**
     * Helper method to create a TextResourceContents object.
     * Resolves the URI based on provided parameters.
     *
     * @param string $text The text content.
     * @param string|null $mimeType Optional MIME type. Defaults to "text/plain".
     * @param array<string, string|int|float|bool> $parameters Parameters for URI resolution.
     * @return TextResourceContents The text resource contents.
     */
    protected function text(string $text, ?string $mimeType = null, array $parameters = []): TextResourceContents
    {
        return new TextResourceContents(
            $this->resolveUri($this->getUri(), $parameters),
            $text,
            $mimeType
        );
    }

    /**
     * Helper method to create a BlobResourceContents object.
     * Resolves the URI based on provided parameters.
     *
     * @param string $data The base64-encoded binary data.
     * @param string $mimeType The MIME type of the blob.
     * @param array<string, string|int|float|bool> $parameters Parameters for URI resolution.
     * @return BlobResourceContents The blob resource contents.
     */
    protected function blob(string $data, string $mimeType, array $parameters = []): BlobResourceContents
    {
        return new BlobResourceContents(
            $this->resolveUri($this->getUri(), $parameters),
            $data,
            $mimeType
        );
    }

    /**
     * Resolves a URI template with given parameters.
     * Replaces placeholders like {key} with corresponding values from parameters.
     *
     * @param string $template The URI template.
     * @param array<string, string|int|float|bool> $parameters Associative array of parameters.
     * @return string The resolved URI.
     */
    private function resolveUri(string $template, array $parameters): string
    {
        $uri = $template;
        foreach ($parameters as $key => $value) {
            $uri = str_replace("{{$key}}", (string)$value, $uri);
        }
        return $uri;
    }
}
