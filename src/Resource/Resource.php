<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

use MCP\Server\Resource\Attribute\ResourceUri;
use MCP\Server\Tool\Content\Annotations;

// Added

abstract class Resource
{
    private ?ResourceUri $metadata = null;
    protected array $config = [];

    // New properties to match the Resource schema for listing
    public readonly string $name;
    public readonly ?string $mimeType;
    public readonly ?int $size;
    public readonly ?Annotations $annotations;

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

    private function initializeMetadata(): void
    {
        $reflection = new \ReflectionClass($this);
        $attrs = $reflection->getAttributes(ResourceUri::class);
        if (count($attrs) > 0) {
            $this->metadata = $attrs[0]->newInstance();
        }
    }

    public function getUri(): string
    {
        return $this->metadata?->uri ?? static::class;
    }

    public function getDescription(): ?string
    {
        return $this->metadata?->description;
    }

    // toArray method to represent the resource for listings
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

    abstract public function read(array $parameters = []): ResourceContents;

    protected function text(string $text, ?string $mimeType = null, array $parameters = []): TextResourceContents
    {
        return new TextResourceContents(
            $this->resolveUri($this->getUri(), $parameters),
            $text,
            $mimeType
        );
    }

    protected function blob(string $data, string $mimeType, array $parameters = []): BlobResourceContents
    {
        return new BlobResourceContents(
            $this->resolveUri($this->getUri(), $parameters),
            $data,
            $mimeType
        );
    }

    private function resolveUri(string $template, array $parameters): string
    {
        $uri = $template;
        foreach ($parameters as $key => $value) {
            $uri = str_replace("{{$key}}", $value, $uri);
        }
        return $uri;
    }
}
