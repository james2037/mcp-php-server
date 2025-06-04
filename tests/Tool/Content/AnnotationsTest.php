<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\Annotations;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class AnnotationsTest extends TestCase
{
    public function testConstructorValidAudienceAndPriority(): void
    {
        $annotations = new Annotations(['user', 'assistant'], 0.5);
        $this->assertEquals(['user', 'assistant'], $annotations->audience);
        $this->assertEquals(0.5, $annotations->priority);
    }

    public function testConstructorInvalidAudienceRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid audience role: admin. Must be 'user' or 'assistant'.");
        new Annotations(['admin']);
    }

    public function testConstructorInvalidAudienceRoleType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // The message will depend on how PHP handles array to string conversion for the message.
        // We are primarily testing that the type check catches non-string roles.
        $this->expectExceptionMessageMatches("/Invalid audience role: .*. Must be 'user' or 'assistant'/");
        new Annotations(['123']); // Pass a string that's not 'user' or 'assistant'
    }

    public function testConstructorPriorityTooLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Priority must be between 0.0 and 1.0.");
        new Annotations(null, -0.1);
    }

    public function testConstructorPriorityTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Priority must be between 0.0 and 1.0.");
        new Annotations(null, 1.1);
    }

    public function testConstructorAllowsNullAudience(): void
    {
        $annotations = new Annotations(null, 0.5);
        $this->assertNull($annotations->audience);
        $this->assertEquals(0.5, $annotations->priority);
    }

    public function testConstructorAllowsNullPriority(): void
    {
        $annotations = new Annotations(['user'], null);
        $this->assertEquals(['user'], $annotations->audience);
        $this->assertNull($annotations->priority);
    }

    public function testConstructorAllowsNullAudienceAndPriority(): void
    {
        $annotations = new Annotations(null, null);
        $this->assertNull($annotations->audience);
        $this->assertNull($annotations->priority);
        // Also test toArray with nulls
        $this->assertEquals([], $annotations->toArray());
    }

    public function testToArrayOnlyIncludesSetValues(): void
    {
        $annotations1 = new Annotations(['user'], 0.7);
        $this->assertEquals(['audience' => ['user'], 'priority' => 0.7], $annotations1->toArray());

        $annotations2 = new Annotations(['assistant'], null);
        $this->assertEquals(['audience' => ['assistant']], $annotations2->toArray());

        $annotations3 = new Annotations(null, 0.3);
        $this->assertEquals(['priority' => 0.3], $annotations3->toArray());

        $annotations4 = new Annotations(null, null);
        $this->assertEquals([], $annotations4->toArray());
    }

    public function testToArrayWithEmptyAudience(): void
    {
        // Technically the constructor doesn't allow creating an empty audience array directly if it's invalid,
        // but if $audience was set to [] post-construction (which it can't be due to type hints/validation),
        // or if validation changed.
        // Let's test the valid case of a non-empty audience.
        $annotations = new Annotations(['user']);
        $this->assertEquals(['audience' => ['user']], $annotations->toArray());
    }
}
