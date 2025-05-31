<?php

namespace MCP\Server\Tests;

use PHPUnit\Framework\TestCase;
use MCP\Server\Server;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Tests\Util\MockTransport;

class ServerErrorHandlingTest extends TestCase
{
    private Server $_server;
    private MockTransport $_transport;

    protected function setUp(): void
    {
        $this->_server = new Server('test-server', '1.0.0');
        $this->_transport = new MockTransport();
        $this->_server->connect($this->_transport);
        $this->_transport->reset(); // Ensure clean transport for each test
    }

    public function testHandlesCapabilityInitializationFailure(): void
    {
        $failingCap = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['failing_cap' => new \stdClass()];
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
        $this->_server->addCapability($failingCap);

        $initMessage = new JsonRpcMessage(
            'initialize',
            ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'init_fail_1'
        );
        $this->_transport->queueIncomingMessages([$initMessage]);
        $this->_server->run();

        $sentMessages = $this->_transport->getAllSentMessages();
        $this->assertCount(1, $sentMessages);
        $response = $sentMessages[0];
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Initialization failed', $responseArray['error']['message']);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $responseArray['error']['code']);
    }

    public function testHandlesShutdownFailure(): void
    {
        $failingCap = new class implements CapabilityInterface {
            public function getCapabilities(): array
            {
                return ['failing_shutdown_cap' => new \stdClass()];
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
        $this->_server->addCapability($failingCap);

        $initMessage = new JsonRpcMessage(
            'initialize',
            ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'init_shutdown_fail_1'
        );
        $shutdownMessage = new JsonRpcMessage('shutdown', [], 'shutdown_fail_1');

        $this->_transport->queueIncomingMessages([$initMessage]);
        $this->_transport->queueIncomingMessages([$shutdownMessage]);
        $this->_server->run();

        $sentMessages = $this->_transport->getAllSentMessages();
        // Expect 2 responses: successful init, then error for shutdown
        $this->assertCount(2, $sentMessages);

        $initResponse = null;
        $shutdownResponse = null;

        foreach($sentMessages as $msg) {
            if ($msg->id === 'init_shutdown_fail_1') { $initResponse = $msg;
            }
            if ($msg->id === 'shutdown_fail_1') { $shutdownResponse = $msg;
            }
        }

        $this->assertNotNull($initResponse);
        $this->assertNotNull($initResponse->result, "Initialization should have succeeded.");

        $this->assertNotNull($shutdownResponse);
        $responseArray = json_decode($shutdownResponse->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Shutdown failed', $responseArray['error']['message']);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $responseArray['error']['code']);
    }

    public function testShutdownOrder(): void
    {
        $shutdownOrder = []; // Passed by reference

        $cap1 = new class ($shutdownOrder, 'cap1') implements CapabilityInterface {
            public function __construct(private array &$orderRef, private string $nameRef)
            {
            }
            public function initialize(): void
            {
            }
            public function shutdown(): void
            {
                $this->orderRef[] = $this->nameRef;
            }
            public function getCapabilities(): array
            {
                return ['cap1_feature' => new \stdClass()];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return false;
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
        };
        $cap2 = new class ($shutdownOrder, 'cap2') implements CapabilityInterface {
            public function __construct(private array &$orderRef, private string $nameRef)
            {
            }
            public function initialize(): void
            {
            }
            public function shutdown(): void
            {
                $this->orderRef[] = $this->nameRef;
            }
            public function getCapabilities(): array
            {
                return ['cap2_feature' => new \stdClass()];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return false;
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                return null;
            }
        };

        $this->_server->addCapability($cap1);
        $this->_server->addCapability($cap2);

        $initMessage = new JsonRpcMessage(
            'initialize',
            ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'init_shutdown_order_1'
        );
        $shutdownMessage = new JsonRpcMessage('shutdown', [], 'shutdown_order_1');

        $this->_transport->queueIncomingMessages([$initMessage]);
        $this->_transport->queueIncomingMessages([$shutdownMessage]);
        $this->_server->run();

        $sentMessages = $this->_transport->getAllSentMessages();
        $this->assertCount(2, $sentMessages); // Init response, Shutdown response

        // Check shutdown order matches registration order
        $this->assertEquals(['cap1', 'cap2'], $shutdownOrder, "Capabilities should be shutdown in the order they were added.");
    }

    // runOneIteration removed
}
