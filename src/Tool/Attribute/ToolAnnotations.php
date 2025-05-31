<?php

namespace MCP\Server\Tool\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ToolAnnotations
{
    public ?string $title = null;
    public ?bool $readOnlyHint = null;
    public ?bool $destructiveHint = null;
    public ?bool $idempotentHint = null;
    public ?bool $openWorldHint = null;

    public function __construct(
        ?string $title = null,
        ?bool $readOnlyHint = null,
        ?bool $destructiveHint = null,
        ?bool $idempotentHint = null,
        ?bool $openWorldHint = null
    ) {
        $this->title = $title;
        $this->readOnlyHint = $readOnlyHint;
        $this->destructiveHint = $destructiveHint;
        $this->idempotentHint = $idempotentHint;
        $this->openWorldHint = $openWorldHint;
    }
}
