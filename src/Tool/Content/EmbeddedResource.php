<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

final class EmbeddedResource implements ContentItemInterface
{
    private array $resource; // Represents TextResourceContents or BlobResourceContents
    private ?Annotations $annotations;

    public function __construct(array $resourceData, ?Annotations $annotations = null)
    {
        if (!isset($resourceData['uri'])) {
            throw new InvalidArgumentException("EmbeddedResource data must contain a 'uri' key.");
        }
        if (!isset($resourceData['text']) && !isset($resourceData['blob'])) {
            throw new InvalidArgumentException("EmbeddedResource data must contain either a 'text' or a 'blob' key.");
        }
        if (isset($resourceData['text']) && !is_string($resourceData['text'])) {
            throw new InvalidArgumentException("EmbeddedResource 'text' must be a string.");
        }
        if (isset($resourceData['blob']) && !is_string($resourceData['blob'])) {
            throw new InvalidArgumentException("EmbeddedResource 'blob' must be a string (base64 encoded).");
        }

        $this->resource = $resourceData;
        $this->annotations = $annotations;
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'resource',
            'resource' => $this->resource,
        ];

        if ($this->annotations !== null) {
            $annotationsArray = $this->annotations->toArray();
            if (!empty($annotationsArray)) {
                $data['annotations'] = $annotationsArray;
            }
        }
        return $data;
    }
}
