<?php

namespace MCP\Server\Tests;

use PHPUnit\Framework\TestCase;
use MCP\Server\Server;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Tests\Util\MockTransport;

class ServerInitializationTest extends TestCase
{
    private Server $server;
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->server = new Server('test-server', '1.0.0');
        $this->transport = new MockTransport();
        $this->server->connect($this->transport);
    }

    public function testRejectsMessageBeforeInitialization(): void
    {
        // Try to send a method before initialization
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'test.method',
                [],
                '1'
            )
        );

        $this->runOneIteration();

        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);

        // Get the response as array to check properties
        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Server not initialized', $responseArray['error']['message']);
    }

    public function testRejectsInvalidProtocolVersion(): void
    {
        // Try to initialize with an unsupported protocol version
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'initialize',
                [
                'protocolVersion' => '1900-01-01',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
                ],
                '1'
            )
        );

        $this->runOneIteration();

        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);

        // Get the response as array to check properties
        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('result', $responseArray);
        $this->assertEquals('2024-11-05', $responseArray['result']['protocolVersion']);
    }

    public function testCapabilityConflictHandling(): void
    {
        // Add two capabilities that handle the same method
        $cap1 = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['test' => true];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return $message->method === 'test.method';
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
            public function initialize(): void
            {
            }
            public function shutdown(): void
            {
            }
        };

        $cap2 = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['test' => true];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return $message->method === 'test.method';
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
            public function initialize(): void
            {
            }
            public function shutdown(): void
            {
            }
        };

        $this->server->addCapability($cap1);
        $this->server->addCapability($cap2);

        // Initialize the server
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'initialize',
                [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
                ],
                '1'
            )
        );

        $this->runOneIteration();

        // Send a test method that both capabilities can handle
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'test.method',
                [],
                '2'
            )
        );

        $this->runOneIteration();

        // The first capability should handle it
        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);
        // Convert to array to check properties
        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayNotHasKey('error', $responseArray);
    }

    public function testInitializationOrder(): void
    {
        $initOrder = [];

        $cap1 = new class ($initOrder) implements CapabilityInterface {
            private array $order;

            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            public function getCapabilities(): array
            {
                return [];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return false;
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
            public function initialize(): void
            {
                $this->order[] = 'cap1';
            }
            public function shutdown(): void
            {
            }
        };

        $cap2 = new class ($initOrder) implements CapabilityInterface {
            private array $order;

            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            public function getCapabilities(): array
            {
                return [];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return false;
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
            public function initialize(): void
            {
                $this->order[] = 'cap2';
            }
            public function shutdown(): void
            {
            }
        };

        $this->server->addCapability($cap1);
        $this->server->addCapability($cap2);

        // Initialize the server
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'initialize',
                [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
                ],
                '1'
            )
        );

        $this->runOneIteration();

        // Check initialization order
        $this->assertEquals(['cap1', 'cap2'], $initOrder);
    }

    public function testInitializationWithUnsupportedCapabilities(): void
    {
        // Client requests capabilities that server doesn't have
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'initialize',
                [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'unsupported_feature' => true,
                    'another_unsupported' => ['some_config' => true]
                ],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
                ],
                '1'
            )
        );

        $this->runOneIteration();

        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);

        // Convert to array to check properties
        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('result', $responseArray);
        $this->assertEquals('test-server', $responseArray['result']['serverInfo']['name']);
        $this->assertEmpty($responseArray['result']['capabilities']);
    }

    private function runOneIteration(): void
    {
        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('handleMessage');
        $method->setAccessible(true);

        $message = $this->transport->receive();
        if ($message) {
            $response = $method->invoke($this->server, $message);
            if ($response) {
                $this->transport->send($response);
            }
        }
    }
}
