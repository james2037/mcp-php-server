<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Attribute;

use Attribute;

/**
 * PHP attribute to define a class as a Tool.
 * It allows specifying the tool's name and description directly on the class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
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
