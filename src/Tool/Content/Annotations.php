<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

/**
 * Represents annotations for content items, such as audience and priority.
 * These annotations provide metadata about how the content should be treated or displayed.
 */
final class Annotations
{
    /** @var string[]|null The intended audience for the content item (e.g., ['user', 'assistant']). */
    public ?array $audience = null;
    /** @var float|null Priority of the content item (0.0 to 1.0). */
    public ?float $priority = null;

    /**
     * Constructs a new Annotations instance.
     *
     * @param array|null $audience The audience for the annotation.
     *                             Each role must be 'user' or 'assistant'.
     * @param float|null $priority The priority of the annotation (0.0 to 1.0).
     * @throws InvalidArgumentException If audience or priority is invalid.
     */
    public function __construct(?array $audience = null, ?float $priority = null)
    {
        if ($audience !== null) {
            foreach ($audience as $role) {
                if (!is_string($role) || !in_array($role, ['user', 'assistant'])) {
                    throw new InvalidArgumentException(
                        "Invalid audience role: {$role}. Must be 'user' or 'assistant'."
                    );
                }
            }
        }
        if ($priority !== null && ($priority < 0 || $priority > 1)) {
            throw new InvalidArgumentException(
                "Priority must be between 0.0 and 1.0."
            );
        }
        $this->audience = $audience;
        $this->priority = $priority;
    }

    /**
     * Converts the annotations to an array.
     *
     * @return array The array representation of the annotations.
     */
    public function toArray(): array
    {
        $data = [];
        if ($this->audience !== null) {
            $data['audience'] = $this->audience;
        }
        if ($this->priority !== null) {
            $data['priority'] = $this->priority;
        }
        return $data;
    }
}
