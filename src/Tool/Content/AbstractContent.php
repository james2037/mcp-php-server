<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Abstract base class for content items.
 * Manages common annotation functionality.
 */
abstract class AbstractContent implements ContentItemInterface
{
    protected ?Annotations $annotations;

    /**
     * Constructs a new AbstractContent instance.
     *
     * @param Annotations|null $annotations Optional annotations.
     */
    public function __construct(?Annotations $annotations = null)
    {
        $this->annotations = $annotations;
    }

    /**
     * Prepares the annotations part of the array representation.
     * This method is intended to be used by subclasses in their toArray() implementations.
     *
     * @return array<string, mixed>
     */
    protected function getAnnotationsArray(): array
    {
        if ($this->annotations !== null) {
            $annotationsArray = $this->annotations->toArray();
            if (!empty($annotationsArray)) {
                return ['annotations' => $annotationsArray];
            }
        }
        return [];
    }

    /**
     * Converts the common content item properties (i.e., annotations) to an array.
     * Subclasses should merge this with their specific data.
     *
     * @return array<string, mixed> The array representation of the common parts.
     */
    public function toArray(): array
    {
        return $this->getAnnotationsArray();
    }
}
