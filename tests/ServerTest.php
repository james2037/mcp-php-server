<?php

namespace MCP\Server\Tests;

use PHPUnit\Framework\TestCase;
use MCP\Server\Server;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tests\Transport\TestableStdioTransport;

class TestCapability implements CapabilityInterface
{
    private array $expectedResponses = [];
    private array $receivedMessages = [];

    public function addExpectedResponse(string $method, ?JsonRpcMessage $response): void
    {
        $this->expectedResponses[$method] = $response;
    }

    public function getReceivedMessages(): array
    {
        return $this->receivedMessages;
    }

    public function getCapabilities(): array
    {
        return ['test' => ['enabled' => true]];
    }

    public function canHandleMessage(JsonRpcMessage $message): bool
    {
        return isset($this->expectedResponses[$message->method]);
    }

    public function handleMessage(JsonRpcMessage $message): ?JsonRpcMessage
    {
        $this->receivedMessages[] = $message;
        return $this->expectedResponses[$message->method] ?? null;
    }

    public function initialize(): void
    {
    }
    public function shutdown(): void
    {
    }
}

class ServerTest extends TestCase
{
    private Server $server;
    private TestableStdioTransport $transport;
    private TestCapability $capability;

    protected function setUp(): void
    {
        $this->server = new Server('test-server', '1.0.0');
        $this->transport = new TestableStdioTransport();
        $this->capability = new TestCapability();

        $this->server->addCapability($this->capability);
        $this->server->connect($this->transport);
    }

    public function testInitialization(): void
    {
        // Send initialize request
        $this->transport->writeToInput(
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
                ],
                'id' => '1'
                ]
            )
        );

        // Run one iteration
        $this->runOneIteration();

        // Check response
        $output = json_decode($this->transport->readFromOutput(), true);
        $this->assertEquals('2.0', $output['jsonrpc']);
        $this->assertEquals('1', $output['id']);
        $this->assertArrayHasKey('serverInfo', $output['result']);
        $this->assertEquals('test-server', $output['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $output['result']);
        $this->assertArrayHasKey('test', $output['result']['capabilities']);
    }

    public function testCapabilityHandling(): void
    {
        // First initialize
        $this->initializeServer();

        // Set up expected response
        $expectedResponse = JsonRpcMessage::result(['success' => true], '2');
        $this->capability->addExpectedResponse('test.method', $expectedResponse);

        // Send test request
        $this->transport->writeToInput(
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'test.method',
                'params' => ['test' => true],
                'id' => '2'
                ]
            )
        );

        // Run one iteration and get the response
        $this->runOneIteration();
        $output = json_decode($this->transport->readFromOutput(), true);

        // Check response
        $this->assertEquals('2', $output['id']);
        $this->assertEquals(['success' => true], $output['result']);

        // Verify capability received message
        $messages = $this->capability->getReceivedMessages();
        $this->assertCount(1, $messages);
        $this->assertEquals('test.method', $messages[0]->method);
    }

    public function testMethodNotFound(): void
    {
        // First initialize
        $this->initializeServer();

        // Send unknown method request
        $this->transport->writeToInput(
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'unknown.method',
                'params' => [],
                'id' => '3'
                ]
            )
        );

        // Run one iteration and get the response
        $this->runOneIteration();
        $output = json_decode($this->transport->readFromOutput(), true);

        // Check error response
        $this->assertEquals('3', $output['id']);
        $this->assertArrayHasKey('error', $output);
        $this->assertEquals(-32601, $output['error']['code']); // METHOD_NOT_FOUND
    }

    private function initializeServer(): void
    {
        $this->transport->writeToInput(
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
                ],
                'id' => '1'
                ]
            )
        );
        $this->runOneIteration();
        $output = $this->transport->readFromOutput();
    }

    private function runOneIteration(): void
    {
        // Create a new ReflectionClass instance for Server
        $reflection = new \ReflectionClass($this->server);

        // Get the private handleMessage method
        $method = $reflection->getMethod('handleMessage');
        $method->setAccessible(true);

        // Get received message
        $message = $this->transport->receive();

        if ($message) {
            // Handle the message
            $response = $method->invoke($this->server, $message);
            if ($response) {
                $this->transport->send($response);
            }
        }
    }
}
