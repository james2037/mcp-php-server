<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\AbstractContent;
use MCP\Server\Tool\Content\Annotations;

// Concrete implementation for testing AbstractContent
class ConcreteContent extends AbstractContent
{
    private string $type;
    private string $value;

    public function __construct(string $type, string $value, ?Annotations $annotations = null)
    {
        parent::__construct($annotations);
        $this->type = $type;
        $this->value = $value;
    }

    public function toArray(): array // Ensure this matches the interface if ContentItemInterface is implemented by AbstractContent
    {
        $data = [
            'type' => $this->type,
            'value' => $this->value,
        ];
        return array_merge($data, parent::toArray());
    }
}
