<?php

declare(strict_types=1);

namespace MCP\Server\Tests\Tool\Content;

use MCP\Server\Tool\Content\AbstractContent;
use MCP\Server\Tool\Content\Annotations;
use PHPUnit\Framework\TestCase;

class AbstractContentTest extends TestCase
{
    public function testToArrayWithAnnotations(): void
    {
        $annotations = new Annotations(['user'], 0.8);
        $content = new ConcreteContent('test_type', 'test_value', $annotations);

        $expectedArray = [
            'type' => 'test_type',
            'value' => 'test_value',
            'annotations' => [
                'audience' => ['user'],
                'priority' => 0.8,
            ],
        ];
        $this->assertEquals($expectedArray, $content->toArray());
    }

    public function testToArrayWithoutAnnotations(): void
    {
        $content = new ConcreteContent('test_type', 'test_value', null);

        $expectedArray = [
            'type' => 'test_type',
            'value' => 'test_value',
            // No 'annotations' key
        ];
        $this->assertEquals($expectedArray, $content->toArray());
    }

    public function testToArrayWithEmptyAnnotationsObject(): void
    {
        // An Annotations object can be empty if constructed with nulls
        $annotations = new Annotations(null, null);
        $content = new ConcreteContent('test_type', 'test_value', $annotations);

        $expectedArray = [
            'type' => 'test_type',
            'value' => 'test_value',
            // No 'annotations' key because the annotations object itself results in an empty array
        ];
        $this->assertEquals($expectedArray, $content->toArray());
    }
}
