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
        // The 'uri' key and the string types for 'text' (if set) and 'blob' (if set)
        // are expected to be guaranteed by the caller, aligning with the PHPDoc type:
        // array{uri: string, text?: string, blob?: string, mimeType?: string}.
        // Static analysis tools can enforce this at the call site.

        // This runtime check ensures that at least one of 'text' or 'blob' is provided.
        if (!isset($resourceData['text']) && !isset($resourceData['blob'])) {
            throw new InvalidArgumentException(
                "EmbeddedResource data must contain either a 'text' or a 'blob' key."
            );
        }

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
