<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

/**
 * Represents an embedded resource as a content item.
 */
final class EmbeddedResource extends AbstractContent // Extend AbstractContent
{
    /** @var array{uri: string, text?: string, blob?: string, mimeType?: string} The actual resource data. */
    private array $resource;
    // Remove private ?Annotations $annotations;

    /**
     * Constructs a new EmbeddedResource instance.
     *
     * @param array{uri: string, text?: string, blob?: string, mimeType?: string} $resourceData
     * @param Annotations|null $annotations Optional annotations.
     * @throws InvalidArgumentException If resource data is invalid.
     */
    public function __construct(
        array $resourceData,
        ?Annotations $annotations = null
    ) {
        parent::__construct($annotations); // Call parent constructor

        if (!isset($resourceData['text']) && !isset($resourceData['blob'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource data must contain either a 'text' or a 'blob' key."
            );
        }

        $this->resource = $resourceData;
    }

    /**
     * Converts the embedded resource to an array.
     *
     * @return array<string, mixed> The array representation of the embedded resource.
     */
    public function toArray(): array
    {
        $contentData = [
            'type' => 'resource',
            'resource' => $this->resource,
        ];

        return array_merge($contentData, parent::toArray()); // Merge with parent::toArray()
    }
}
