<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Defines the contract for content items that can be part of a tool's execution result.
 * All content items must be convertible to an array suitable for JSON serialization
 * as part of the tool's response.
 */
interface ContentItemInterface
{
    /**
     * Converts the content item to an array.
     *
     * @return array The array representation of the content item.
     */
    public function toArray(): array;
}
