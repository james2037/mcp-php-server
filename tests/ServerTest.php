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

    public function resetReceivedMessages(): void
    {
        $this->receivedMessages = [];
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
        $this->transport = new TestableStdioTransport(); // Assumes TestableStdioTransport is available
        $this->capability = new TestCapability();

        $this->server->addCapability($this->capability);
        $this->server->connect($this->transport);
    }

    private function initializeServerAndGetInitResponse(string $protocolVersion = '2025-03-26'): array
    {
        $initRequest = [
            'jsonrpc' => '2.0', 'method' => 'initialize',
            'params' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => new \stdClass(), // Empty client capabilities object
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
            ],
            'id' => 'init_id'
        ];
        // Add a shutdown request to make the server loop exit cleanly
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_id'];

        $this->transport->writeToInput(json_encode($initRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest)); // Add this line

        $this->server->run(); // Server runs and processes both, then exits due to shutdown

        $responses = $this->transport->readMultipleJsonOutputs();

        $this->assertGreaterThanOrEqual(1, count($responses), "Should have at least init response.");
        $initResponse = null;
        foreach ($responses as $resp) {
            if (isset($resp['id']) && $resp['id'] === 'init_id') {
                $initResponse = $resp;
                break;
            }
        }
        $this->assertNotNull($initResponse, "Initialize response not found in output. Got: " . json_encode($responses));
        return $initResponse;
    }

    public function testInitialization(): void
    {
        $initResponse = $this->initializeServerAndGetInitResponse();

        $this->assertEquals('2.0', $initResponse['jsonrpc']);
        $this->assertEquals('init_id', $initResponse['id']);
        $this->assertArrayHasKey('result', $initResponse);
        $this->assertArrayHasKey('protocolVersion', $initResponse['result']);
        $this->assertEquals('2025-03-26', $initResponse['result']['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $initResponse['result']);
        $this->assertEquals('test-server', $initResponse['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $initResponse['result']);
        $this->assertArrayHasKey('test', $initResponse['result']['capabilities']);
        $this->assertEquals(['enabled' => true], $initResponse['result']['capabilities']['test']);
        $this->assertArrayHasKey('logging', $initResponse['result']['capabilities']);
        $this->assertEquals(new \stdClass(), $initResponse['result']['capabilities']['logging']);
        $this->assertArrayHasKey('completions', $initResponse['result']['capabilities']);
        $this->assertEquals(new \stdClass(), $initResponse['result']['capabilities']['completions']);
    }

    public function testCapabilityHandling(): void
    {
        // Initialize server (response is tested in testInitialization)
        $this->initializeServerAndGetInitResponse();
        // Clear outputs from initialization
        $this->transport->readMultipleJsonOutputs();
        // Reset received messages in capability from initialize call
        $this->capability->resetReceivedMessages();


        $expectedResponse = JsonRpcMessage::result(['success' => true], 'cap_test_id');
        $this->capability->addExpectedResponse('test.method', $expectedResponse);

        $testRequest = ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['test' => true], 'id' => 'cap_test_id'];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_id_2'];

        $this->transport->writeToInput(json_encode($testRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest));

        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();

        $testResponse = null;
        foreach ($responses as $resp) {
            if (isset($resp['id']) && $resp['id'] === 'cap_test_id') {
                $testResponse = $resp;
                break;
            }
        }
        $this->assertNotNull($testResponse, "Test method response not found. Got: " . json_encode($responses));
        $this->assertEquals('cap_test_id', $testResponse['id']);
        $this->assertEquals(['success' => true], $testResponse['result']);

        $receivedCapabilityMessages = $this->capability->getReceivedMessages();
        $this->assertCount(1, $receivedCapabilityMessages, "Capability should have received one message for 'test.method'.");
        $this->assertEquals('test.method', $receivedCapabilityMessages[0]->method);
    }

    public function testMethodNotFound(): void
    {
        // Initialize server
        $this->initializeServerAndGetInitResponse();
        // Clear outputs from initialization
        $this->transport->readMultipleJsonOutputs();

        $unknownMethodRequest = ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'params' => [], 'id' => 'unknown_id'];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_id_3'];

        $this->transport->writeToInput(json_encode($unknownMethodRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest));

        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();

        $errorResponse = null;
        foreach ($responses as $resp) {
            if (isset($resp['id']) && $resp['id'] === 'unknown_id') {
                $errorResponse = $resp;
                break;
            }
        }
        $this->assertNotNull($errorResponse, "Error response for unknown method not found. Got: " . json_encode($responses));
        $this->assertEquals('unknown_id', $errorResponse['id']);
        $this->assertArrayHasKey('error', $errorResponse);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $errorResponse['error']['code']);
    }

    // Removed initializeServer() and runOneIteration()

    // Helper methods for environment variables
    private function setEnvVar(string $name, string $value): void
    {
        putenv("$name=$value");
    }

    private function clearEnvVar(string $name): void
    {
        putenv($name); // Clears the environment variable
    }

    protected function tearDown(): void
    {
        // Ensure env vars are cleared after tests that might set them
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
        parent::tearDown();
    }

    public function testBatchRequestProcessing(): void
    {
        $this->initializeServerAndGetInitResponse(); // Initialize server
        $this->transport->readMultipleJsonOutputs(); // Clear init/shutdown responses
        $this->capability->resetReceivedMessages(); // Reset capability messages

        $batchRequests = [
            ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['data' => 'batch1'], 'id' => 'batch_req_1'],
            ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'id' => 'batch_req_2'],
            ['jsonrpc' => '2.0', 'method' => 'notify.method', 'params' => ['data' => 'notify1']]
        ];
        $this->capability->addExpectedResponse('test.method', JsonRpcMessage::result(['received' => 'batch1'], 'batch_req_1'));

        $this->transport->writeToInput(json_encode($batchRequests));
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_batch_id'];
        $this->transport->writeToInput(json_encode($shutdownRequest));

        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();

        // Expect 2 "top-level" outputs: the batch response array, then the shutdown response object
        $this->assertCount(2, $responses, "Should have batch response and shutdown response. Got: " . json_encode($responses));

        $batchResponseArray = $responses[0]; // First output is the batch response
        $this->assertIsArray($batchResponseArray);
        $this->assertCount(2, $batchResponseArray, "Batch response should contain 2 items (1 result, 1 error)");

        // Check first response in batch (success)
        $response1 = null;
        foreach($batchResponseArray as $r) { if (isset($r['id']) && $r['id'] === 'batch_req_1') $response1 = $r; }
        $this->assertNotNull($response1, "Response for batch_req_1 not found in batch.");
        $this->assertArrayHasKey('result', $response1);
        $this->assertEquals(['received' => 'batch1'], $response1['result']);

        // Check second response in batch (error)
        $response2 = null;
        foreach($batchResponseArray as $r) { if (isset($r['id']) && $r['id'] === 'batch_req_2') $response2 = $r; }
        $this->assertNotNull($response2, "Response for batch_req_2 not found in batch.");
        $this->assertArrayHasKey('error', $response2);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $response2['error']['code']);
    }

    public function testAuthorizationRequiredAndSuccessful(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->setEnvVar('MCP_AUTHORIZATION_TOKEN', 'test_token_secret');

        $initResponse = $this->initializeServerAndGetInitResponse(); // This helper sends init and shutdown
        $this->assertArrayHasKey('result', $initResponse, "Initialization should succeed with correct token.");
        $this->assertEquals('init_id', $initResponse['id']);

        // initializeServerAndGetInitResponse already processed the shutdown too.
        // We can verify that the shutdown response was also processed.
        $allResponses = $this->transport->readMultipleJsonOutputs(); // Read any remaining (should be none if helper consumed all)
        // If initializeServerAndGetInitResponse consumes all, this will be empty.
        // If it only returns init, then shutdown will be here.
        // The helper currently consumes all and finds init. So this should be empty.
        $this->assertEmpty($allResponses, "No more responses should be pending after initializeServerAndGetInitResponse.");

        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenMissing(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');

        $initRequest = ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'clientInfo'=>['name'=>'c','version'=>'1']], 'id' => 'auth_test_id_missing'];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_auth_missing'];

        $this->transport->writeToInput(json_encode($initRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest));
        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();
        // Server's run loop continues after init error and processes shutdown.
        $this->assertCount(2, $responses, "Should have init error and shutdown response. Got: " . json_encode($responses));

        $errorResponse = null;
        foreach($responses as $r) { if (isset($r['id']) && $r['id'] === 'auth_test_id_missing') $errorResponse = $r; }
        $this->assertNotNull($errorResponse, "Error response for missing token not found.");

        $this->assertArrayHasKey('error', $errorResponse);
        $this->assertEquals(-32000, $errorResponse['error']['code']);
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenInvalid(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->setEnvVar('MCP_AUTHORIZATION_TOKEN', 'wrong_token_secret');

        $initRequest = ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'clientInfo'=>['name'=>'c','version'=>'1']], 'id' => 'auth_test_id_invalid'];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_auth_invalid'];

        $this->transport->writeToInput(json_encode($initRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest));
        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();
        // Server's run loop continues after init error and processes shutdown.
        $this->assertCount(2, $responses, "Should have init error and shutdown response. Got: " . json_encode($responses));

        $errorResponse = null;
        foreach($responses as $r) { if (isset($r['id']) && $r['id'] === 'auth_test_id_invalid') $errorResponse = $r; }
        $this->assertNotNull($errorResponse, "Error response for invalid token not found.");

        $this->assertArrayHasKey('error', $errorResponse);
        $this->assertEquals(-32001, $errorResponse['error']['code']);
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testSetLogLevelRequest(): void
    {
        $initResponse = $this->initializeServerAndGetInitResponse();
        $this->assertArrayHasKey('result', $initResponse);
        $this->transport->readMultipleJsonOutputs(); // Clear output from init helper

        $setLevelRequest = ['jsonrpc' => '2.0', 'method' => 'logging/setLevel', 'params' => ['level' => 'debug'], 'id' => 'set_level_id_1'];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_set_level'];

        $this->transport->writeToInput(json_encode($setLevelRequest));
        $this->transport->writeToInput(json_encode($shutdownRequest));

        $this->server->run();

        $responses = $this->transport->readMultipleJsonOutputs();
        $this->assertCount(2, $responses, "Should have setLevel response and shutdown response. Got: " . json_encode($responses));

        $setLevelResponse = null;
        foreach($responses as $r) { if (isset($r['id']) && $r['id'] === 'set_level_id_1') $setLevelResponse = $r; }
        $this->assertNotNull($setLevelResponse, "SetLevel response not found.");
        $this->assertArrayHasKey('result', $setLevelResponse);
        $this->assertEquals([], $setLevelResponse['result']);
    }
}
