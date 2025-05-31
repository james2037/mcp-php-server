<?php

/**
 * This file contains the ToolAnnotations class.
 */

namespace MCP\Server\Tool\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
/**
 * Represents annotations for a tool.
 */
final class ToolAnnotations
{
    public ?string $title = null;
    public ?bool $readOnlyHint = null;
    public ?bool $destructiveHint = null;
    public ?bool $idempotentHint = null;
    public ?bool $openWorldHint = null;

    /**
     * Constructs a new ToolAnnotations instance.
     *
     * @param string|null $title The title of the tool.
     * @param bool|null $readOnlyHint Hint that the tool is read-only.
     * @param bool|null $destructiveHint Hint that the tool is destructive.
     * @param bool|null $idempotentHint Hint that the tool is idempotent.
     * @param bool|null $openWorldHint Hint that the tool operates in an open world.
     */
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
