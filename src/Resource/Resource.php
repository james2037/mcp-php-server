<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

use MCP\Server\Resource\Attribute\ResourceUri;

abstract class Resource
{
    private ?ResourceUri $metadata = null;
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
