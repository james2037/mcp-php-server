<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Attribute;

use Attribute;

/**
 * PHP attribute to define a parameter for a Tool method.
 * It allows specifying the parameter's name, type, description, and whether it's required.
 * This attribute is repeatable for methods that have multiple annotated parameters.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
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
