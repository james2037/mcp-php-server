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
        $this->transport->reset(); // Ensure clean transport for each test
    }

    public function testRejectsMessageBeforeInitialization(): void
    {
        $testMethodMessage = new JsonRpcMessage('test.method', [], '1');
        $this->transport->queueIncomingMessages([$testMethodMessage]);
        // Server will consume the message, try to process, fail, and then transport will be "closed".
        $this->server->run();

        $sentMessages = $this->transport->getAllSentMessages();
        $this->assertCount(1, $sentMessages);
        $response = $sentMessages[0];
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('error', $responseArray);
        $this->assertEquals('Server not initialized', $responseArray['error']['message']);
        $this->assertEquals(JsonRpcMessage::INVALID_REQUEST, $responseArray['error']['code']);
    }

    public function testInitializeResponseDetails(): void // Renamed from testRejectsInvalidProtocolVersion
    {
        $initMessage = new JsonRpcMessage(
            'initialize',
            [
                'protocolVersion' => '1900-01-01', // Client sends an old/any version
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
            ],
            'init_1'
        );
        $this->transport->queueIncomingMessages([$initMessage]);
        $this->server->run();

        $sentMessages = $this->transport->getAllSentMessages();
        $this->assertCount(1, $sentMessages);
        $response = $sentMessages[0];
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('result', $responseArray);
        $this->assertEquals('2025-03-26', $responseArray['result']['protocolVersion']); // Server responds with its version
        $this->assertArrayHasKey('logging', $responseArray['result']['capabilities']);
        $this->assertEquals([], $responseArray['result']['capabilities']['logging']); // Empty JSON object {} decodes to empty array []
        $this->assertArrayHasKey('completions', $responseArray['result']['capabilities']);
        $this->assertEquals([], $responseArray['result']['capabilities']['completions']); // Empty JSON object {} decodes to empty array []
        $this->assertArrayHasKey('instructions', $responseArray['result']);
        $this->assertIsString($responseArray['result']['instructions']);
    }

    public function testCapabilityConflictHandling(): void
    {
        $handledBy = null; // Variable to track who handled the message

        $cap1 = new class ($handledBy) implements CapabilityInterface {
            // @phpstan-ignore-next-line - $handledByRef is used by reference to capture handler for external assertion.
            public function __construct(private &$handledByRef)
            {
            }
            /** @return array<string, mixed> */
            public function getCapabilities(): array
            {
                return ['conflict_cap' => true];
            }
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return $message->method === 'conflict.method';
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                $this->handledByRef = 'cap1';
                return JsonRpcMessage::result(['handled_by' => 'cap1'], $message->id);
            }
            public function initialize(): void
            {
            }
            public function shutdown(): void
            {
            }
        };

        $cap2 = new class ($handledBy) implements CapabilityInterface {
            // @phpstan-ignore-next-line - $handledByRef is used by reference to capture handler for external assertion.
            public function __construct(private &$handledByRef)
            {
            }
            /** @return array<string, mixed> */
            public function getCapabilities(): array
            {
                return ['conflict_cap_alt' => true];
            }
            // Different capability name to avoid simple array merge overwrite
            public function canHandleMessage(JsonRpcMessage $message): bool
            {
                return $message->method === 'conflict.method';
            }
            public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
            {
                $this->handledByRef = 'cap2';
                return JsonRpcMessage::result(['handled_by' => 'cap2'], $message->id);
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
        // cap2 added second

        $initMessage = new JsonRpcMessage('initialize', ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']], 'init_conflict');
        $conflictMessage = new JsonRpcMessage('conflict.method', [], 'conflict_call_1');

        $this->transport->queueIncomingMessages([$initMessage]);
        $this->transport->queueIncomingMessages([$conflictMessage]);
        // Add a shutdown message to ensure clean exit for other tests if run() is used more broadly
        $shutdownMessage = new JsonRpcMessage('shutdown', [], 'shutdown_conflict');
        $this->transport->queueIncomingMessages([$shutdownMessage]);

        $this->server->run();

        $sentMessages = $this->transport->getAllSentMessages();
        $this->assertCount(3, $sentMessages); // Init, conflict_method_response, shutdown_response

        $conflictResponse = null;
        foreach ($sentMessages as $msg) {
            if ($msg->id === 'conflict_call_1') {
                $conflictResponse = $msg;
                break;
            }
        }
        $this->assertNotNull($conflictResponse, "Response to conflict.method not found.");
        $this->assertNull($conflictResponse->error, "Conflict method call should not result in an error.");
        $this->assertNotNull($conflictResponse->result);
        $this->assertEquals('cap1', $conflictResponse->result['handled_by']); // First registered capability should handle it
        $this->assertEquals('cap1', $handledBy); // Confirm internal tracking matches
    }

    public function testInitializationOrder(): void
    {
        $initOrder = []; // Passed by reference

        $cap1 = new class ($initOrder) implements CapabilityInterface { /* ... constructor and methods ... */
            // @phpstan-ignore-next-line - $orderRef is used by reference to capture initialization order for external assertion.
            public function __construct(private array &$orderRef)
            {
            }
            public function initialize(): void
            {
                $this->orderRef[] = 'cap1';
            }
            /** @return array<string, mixed> */
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
            public function shutdown(): void
            {
            }
        };
        $cap2 = new class ($initOrder) implements CapabilityInterface { /* ... constructor and methods ... */
            // @phpstan-ignore-next-line - $orderRef is used by reference to capture initialization order for external assertion.
            public function __construct(private array &$orderRef)
            {
            }
            public function initialize(): void
            {
                $this->orderRef[] = 'cap2';
            }
            /** @return array<string, mixed> */
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
            public function shutdown(): void
            {
            }
        };

        $this->server->addCapability($cap1);
        $this->server->addCapability($cap2);

        $initMessage = new JsonRpcMessage('initialize', ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']], 'init_order_1');
        $this->transport->queueIncomingMessages([$initMessage]);
        $this->server->run();

        $this->assertEquals(['cap1', 'cap2'], $initOrder, "Capabilities should be initialized in the order they were added.");
    }

    public function testInitializationWithUnsupportedCapabilities(): void
    {
        $initMessage = new JsonRpcMessage(
            'initialize',
            [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object)[ // Send as object as per JSON-RPC
                    'unsupported_feature' => true,
                    'another_unsupported' => ['some_config' => true]
                ],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0']
            ],
            'init_unsupported_1'
        );
        $this->transport->queueIncomingMessages([$initMessage]);
        $this->server->run();

        $sentMessages = $this->transport->getAllSentMessages();
        $this->assertCount(1, $sentMessages);
        $response = $sentMessages[0];
        $this->assertNotNull($response);

        $responseArray = json_decode($response->toJson(), true);
        $this->assertArrayHasKey('result', $responseArray);
        $this->assertEquals('test-server', $responseArray['result']['serverInfo']['name']);

        // Server announces its own capabilities, not client's.
        // Check for default 'logging' and 'completions'.
        $this->assertArrayHasKey('logging', $responseArray['result']['capabilities']);
        $this->assertEquals([], $responseArray['result']['capabilities']['logging']); // Empty JSON object {} decodes to empty array []
        $this->assertArrayHasKey('completions', $responseArray['result']['capabilities']);
        $this->assertEquals([], $responseArray['result']['capabilities']['completions']); // Empty JSON object {} decodes to empty array []
        $this->assertArrayNotHasKey('unsupported_feature', $responseArray['result']['capabilities']);
    }

    public function testLoggingSetLevelAndNotificationViaMockTransport(): void
    {
        // Reset server and transport for this specific test to ensure clean state
        // This is important because clientSetLogLevel is stateful on the Server instance
        $this->server = new Server('test-server-log', '1.0.0');
        $this->transport = new MockTransport(); // Fresh transport
        $this->server->connect($this->transport);
        // No capabilities needed for this specific server feature test

        $initMsg = new JsonRpcMessage('initialize', ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'c', 'version' => '1']], 'init_log_test');
        $setLevelMsg = new JsonRpcMessage('logging/setLevel', ['level' => 'debug'], 'set_level_log_test');

        // Queue messages in separate "receive" batches
        $this->transport->queueIncomingMessages([$initMsg]);
        $this->transport->queueIncomingMessages([$setLevelMsg]);

        // Server processes init, then setLevel. isClosed will be true after these.
        $this->server->run();

        // Server::logMessage sends immediately if conditions met.
        // So, we trigger a log *after* setLevel has been processed.
        $this->server->logMessage('debug', 'test client log message', 'test-logger', ['extra' => 'data']);

        $sentMessages = $this->transport->getAllSentMessages();
        // Expected: 1. init_response, 2. setLevel_internal_log, 3. setLevel_response, 4. explicit_log_notification
        $this->assertCount(4, $sentMessages, "Should have 4 messages: init_resp, setLevel_log, setLevel_resp, explicit_log.");

        $logNotification = null;
        // Find the explicit log notification which has 'test-logger'
        foreach ($sentMessages as $msg) {
            if ($msg->method === 'notifications/message' && isset($msg->params['logger']) && $msg->params['logger'] === 'test-logger') {
                $logNotification = $msg;
                break;
            }
        }
        $this->assertNotNull($logNotification, "Explicitly triggered log notification with 'test-logger' not found.");
        $this->assertNull($logNotification->id, "Log notification should not have an ID.");
        $this->assertEquals('debug', $logNotification->params['level']);
        $this->assertEquals('test client log message', $logNotification->params['message']);
        $this->assertEquals('test-logger', $logNotification->params['logger']);
        $this->assertEquals(['extra' => 'data'], $logNotification->params['data']);
    }

    // runOneIteration removed
}
