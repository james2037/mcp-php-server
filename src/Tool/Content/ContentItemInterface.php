<?php

/**
 * This file contains the ContentItemInterface.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

/**
 * Interface for content items.
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
