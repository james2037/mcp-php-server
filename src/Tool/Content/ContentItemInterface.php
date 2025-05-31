<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

interface ContentItemInterface
{
    public function toArray(): array;
}
