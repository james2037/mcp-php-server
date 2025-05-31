<?php

/**
 * This file contains the Tool class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * Represents a tool.
 */
final class Tool
{
    /**
     * Constructs a new Tool instance.
     *
     * @param string $name The name of the tool.
     * @param string $description The description of the tool.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description
    ) {
    }
}
