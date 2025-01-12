<?php

namespace MCP\Server\Tests\Capability;

use MCP\Server\Capability\ResourcesCapability;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceContents;
use MCP\Server\Resource\Attribute\ResourceUri;
use PHPUnit\Framework\TestCase;

#[ResourceUri('test://static')]
class MockResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        return $this->text('Static content');
    }
}

#[ResourceUri('test://users/{userId}')]
class ParameterizedResource extends Resource
{
    public function read(array $parameters = []): ResourceContents
    {
        if (!isset($parameters['userId'])) {
            throw new \RuntimeException('Missing userId parameter');
        }
        return $this->text("User {$parameters['userId']}");
    }
}

class ResourcesCapabilityTest extends TestCase
{
    private ResourcesCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new ResourcesCapability();
        $this->capability->addResource(new MockResource());
    }

    public function testGetCapabilities(): void
    {
        $caps = $this->capability->getCapabilities();
        $this->assertArrayHasKey('resources', $caps);
        $this->assertArrayHasKey('subscribe', $caps['resources']);
        $this->assertArrayHasKey('listChanged', $caps['resources']);
    }

    public function testHandleList(): void
    {
        $request = new JsonRpcMessage('resources/list', [], '1');
        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('resources', $response->result);
        $this->assertCount(1, $response->result['resources']);
        $this->assertEquals('test://static', $response->result['resources'][0]['uri']);
    }

    public function testHandleRead(): void
    {
        $request = new JsonRpcMessage(
            'resources/read',
            [
            'uri' => 'test://static'
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('contents', $response->result);
        $this->assertEquals('Static content', $response->result['contents'][0]->text);
    }

    public function testHandleReadWithParameters(): void
    {
        $this->capability->addResource(new ParameterizedResource());

        $request = new JsonRpcMessage(
            'resources/read',
            [
            'uri' => 'test://users/123'
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertEquals('User 123', $response->result['contents'][0]->text);
    }

    public function testHandleReadUnknownResource(): void
    {
        $request = new JsonRpcMessage(
            'resources/read',
            [
            'uri' => 'test://unknown'
            ],
            '1'
        );

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertTrue($response->isError());
        $this->assertStringContainsString('Resource not found', $response->error['message']);
    }

    public function testHandleMissingUri(): void
    {
        $request = new JsonRpcMessage('resources/read', [], '1');

        $response = $this->capability->handleMessage($request);

        $this->assertNotNull($response);
        $this->assertTrue($response->isError());
        $this->assertStringContainsString('Missing uri parameter', $response->error['message']);
    }
}
