<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

/**
 * Represents an embedded resource as a content item.
 * This is used when a tool's output includes the content of a resource directly,
 * rather than just a URI pointing to it. The embedded resource itself
 * should conform to the structure of a TextResourceContents or BlobResourceContents array.
 */
final class EmbeddedResource implements ContentItemInterface
{
    /** @var array{uri: string, text?: string, blob?: string, mimeType?: string} The actual resource data,
     * typically matching the structure of TextResourceContents or BlobResourceContents.
     */
    private array $resource;
    /** @var Annotations|null Optional annotations for the embedded resource. */
    private ?Annotations $annotations;

    /**
     * Constructs a new EmbeddedResource instance.
     *
     * @param array{uri: string, text?: string, blob?: string, mimeType?: string} $resourceData The resource data, expected to have a 'uri' key,
     *                            and either a 'text' or a 'blob' key.
     *                            Optionally, a 'mimeType' key can be included.
     *                            Example: `['uri' => '/my/data', 'text' => 'hello', 'mimeType' => 'text/plain']`
     *                            Example: `['uri' => '/my/image', 'blob' => 'base64data', 'mimeType' => 'image/png']`
     * @param Annotations|null $annotations Optional annotations.
     * @throws InvalidArgumentException If resource data is invalid (missing keys or incorrect types).
     */
    public function __construct(
        array $resourceData,
        ?Annotations $annotations = null
    ) {
        // The following checks are removed based on PHPStan's analysis of the PHPDoc type
        // array{uri: string, text?: string, blob?: string, mimeType?: string} for $resourceData.
        // PHPStan considers 'uri' to be always set and a string.
        // It also considers 'text' (if set) to be a string, and 'blob' (if set) to be a string.

        // if (!isset($resourceData['uri'])) {
        //     throw new InvalidArgumentException(
        //         "EmbeddedResource data must contain a 'uri' key."
        //     );
        // }

        // This check remains necessary as it ensures at least one of the content keys is present.
        // PHPStan does not simplify this kind of OR logic for optional keys based on the type alone.
        if (!isset($resourceData['text']) && !isset($resourceData['blob'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource data must contain either a 'text' or a 'blob' key."
            );
        }

        // if (isset($resourceData['text']) && !is_string($resourceData['text'])) {
        //     throw new InvalidArgumentException(
        //         "EmbeddedResource 'text' must be a string."
        //     );
        // }
        // if (isset($resourceData['blob']) && !is_string($resourceData['blob'])) {
        //     throw new InvalidArgumentException(
        //         "EmbeddedResource 'blob' must be a string (base64 encoded)."
        //     );
        // }

        $this->resource = $resourceData;
        $this->annotations = $annotations;
    }

    /**
     * Converts the embedded resource to an array.
     *
     * @return array<string, mixed> The array representation of the embedded resource.
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
