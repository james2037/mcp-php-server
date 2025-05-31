<?php

/**
 * This file contains the Parameter class.
 */

declare(strict_types=1);

namespace MCP\Server\Tool\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
/**
 * Represents a parameter for a tool.
 */
final class Parameter
{
    /**
     * Constructs a new Parameter instance.
     *
     * @param string $name The name of the parameter.
     * @param string $type The type of the parameter.
     * @param string|null $description The description of the parameter.
     * @param bool $required Whether the parameter is required.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly ?string $description = null,
        public readonly bool $required = true
    ) {
    }
}
