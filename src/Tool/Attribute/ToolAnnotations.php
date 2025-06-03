<?php

namespace MCP\Server\Tool\Attribute;

/**
 * PHP attribute to define various annotations for a Tool class,
 * such as title, read-only hint, destructive hint, idempotent hint, and open-world hint.
 * These annotations provide metadata about the tool's behavior and characteristics.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ToolAnnotations
{
    /** Optional title for the tool. */
    public ?string $title = null;
    /** Hint indicating if the tool is read-only (i.e., does not change state). */
    public ?bool $readOnlyHint = null;
    /** Hint indicating if the tool has destructive effects (e.g., deletes data). */
    public ?bool $destructiveHint = null;
    /** Hint indicating if the tool is idempotent (i.e., multiple identical calls have the same effect as one). */
    public ?bool $idempotentHint = null;
    /** Hint indicating if the tool operates with open-world assumptions (i.e., its knowledge of the world is incomplete). */
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
