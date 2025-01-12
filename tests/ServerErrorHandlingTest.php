<?php

namespace MCP\Server\Tests;

use PHPUnit\Framework\TestCase;
use MCP\Server\Server;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Tests\Util\MockTransport;

class ServerErrorHandlingTest extends TestCase
{
    private Server $server;
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->server = new Server('test-server', '1.0.0');
        $this->transport = new MockTransport();
        $this->server->connect($this->transport);
    }

    public function testHandlesCapabilityInitializationFailure(): void
    {
        // Create a capability that throws during initialization
        $failingCap = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['test' => true];
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
                throw new \RuntimeException('Initialization failed');
            }

            public function shutdown(): void
            {
            }
        };

        $this->server->addCapability($failingCap);

        // Try to initialize
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

        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Initialization failed', $responseArray['error']['message']);
    }

    public function testHandlesShutdownFailure(): void
    {
        // Create a capability that throws during shutdown
        $failingCap = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['test' => true];
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
            }

            public function shutdown(): void
            {
                throw new \RuntimeException('Shutdown failed');
            }
        };

        $this->server->addCapability($failingCap);

        // First initialize
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

        // Now try to shutdown
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'shutdown',
                [],
                '2'
            )
        );

        $this->runOneIteration();

        $response = $this->transport->getLastSent();
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Shutdown failed', $responseArray['error']['message']);
    }

    public function testShutdownOrder(): void
    {
        $shutdownOrder = [];

        // Create capabilities that track shutdown order
        $cap1 = new class ($shutdownOrder, 'cap1') implements CapabilityInterface {
            private array $order;
            private string $name;

            public function __construct(array &$order, string $name)
            {
                $this->order = &$order;
                $this->name = $name;
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
            }
            public function shutdown(): void
            {
                $this->order[] = $this->name;
            }
        };

        $cap2 = new class ($shutdownOrder, 'cap2') implements CapabilityInterface {
            private array $order;
            private string $name;

            public function __construct(array &$order, string $name)
            {
                $this->order = &$order;
                $this->name = $name;
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
            }
            public function shutdown(): void
            {
                $this->order[] = $this->name;
            }
        };

        $this->server->addCapability($cap1);
        $this->server->addCapability($cap2);

        // First initialize
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

        // Now shutdown
        $this->transport->queueIncoming(
            new JsonRpcMessage(
                'shutdown',
                [],
                '2'
            )
        );

        $this->runOneIteration();

        // Check shutdown order matches registration order
        $this->assertEquals(['cap1', 'cap2'], $shutdownOrder);
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
