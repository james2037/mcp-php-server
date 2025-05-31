<?php

declare(strict_types=1);

namespace MCP\Server\Tool\Content;

use InvalidArgumentException;

final class Annotations
{
    public ?array $audience = null;
    public ?float $priority = null;

    public function __construct(?array $audience = null, ?float $priority = null)
    {
        if ($audience !== null) {
            foreach ($audience as $role) {
                if (!is_string($role) || !in_array($role, ['user', 'assistant'])) {
                    throw new InvalidArgumentException("Invalid audience role: {$role}. Must be 'user' or 'assistant'.");
                }
            }
        }
        if ($priority !== null && ($priority < 0 || $priority > 1)) {
            throw new InvalidArgumentException("Priority must be between 0.0 and 1.0.");
        }
        $this->audience = $audience;
        $this->priority = $priority;
    }

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
