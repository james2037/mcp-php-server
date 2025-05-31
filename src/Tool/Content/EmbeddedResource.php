<?php

/**
 * This file contains the EmbeddedResource class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

/**
 * Represents an embedded resource content item.
 */
final class EmbeddedResource implements ContentItemInterface
{
    // Represents TextResourceContents or BlobResourceContents
    private array $resource;
    private ?Annotations $annotations;

    /**
     * Constructs a new EmbeddedResource instance.
     *
     * @param array $resourceData The resource data.
     *                            Must contain 'uri' and either 'text' or 'blob'.
     * @param Annotations|null $annotations Optional annotations.
     * @throws InvalidArgumentException If resource data is invalid.
     */
    public function __construct(
        array $resourceData,
        ?Annotations $annotations = null
    ) {
        if (!isset($resourceData['uri'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource data must contain a 'uri' key."
            );
        }
        if (!isset($resourceData['text']) && !isset($resourceData['blob'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource data must contain either a 'text' or a 'blob' key."
            );
        }
        if (isset($resourceData['text']) && !is_string($resourceData['text'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource 'text' must be a string."
            );
        }
        if (isset($resourceData['blob']) && !is_string($resourceData['blob'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource 'blob' must be a string (base64 encoded)."
            );
        }

        $this->resource = $resourceData;
        $this->annotations = $annotations;
    }

    /**
     * Converts the embedded resource to an array.
     *
     * @return array The array representation of the embedded resource.
     */
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
