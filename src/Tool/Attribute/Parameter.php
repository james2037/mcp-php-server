<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class Parameter
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly ?string $description = null,
        public readonly bool $required = true
    ) {
    }
}
