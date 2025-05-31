<?php

namespace MCP\Server\Tests;

require_once __DIR__ . '/Transport/StdioTransportTest.php';

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
    private Server $_server;
    private TestableStdioTransport $_transport;
    private TestCapability $_capability;

    protected function setUp(): void
    {
        $this->_server = new Server('test-server', '1.0.0');
        $this->_transport = new TestableStdioTransport();
        $this->_capability = new TestCapability();

        $this->_server->addCapability($this->_capability);
        $this->_server->connect($this->_transport);
    }

    private function _queueInitializeRequest(string $protocolVersion = '2025-03-26'): string
    {
        $initRequestId = 'init_id_' . uniqid();
        $initRequest = [
            'jsonrpc' => '2.0', 'method' => 'initialize',
            'params' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
            ],
            'id' => $initRequestId
        ];
        $this->_transport->writeToInput(json_encode($initRequest));
        return $initRequestId;
    }

    private function _findResponseById(array $responsesToSearch, string $idToFind): ?array
    {
        $idToFindStr = (string)$idToFind;
        foreach ($responsesToSearch as $item) { // $item is typically a decoded JSON line
            // Case 1: $item is a single response object e.g. {'jsonrpc': ..., 'id': ...}
            if (is_array($item) && isset($item['jsonrpc']) && isset($item['id'])) {
                if ((string)$item['id'] === $idToFindStr) {
                    return $item;
                }
            }
            // Case 2: $item is an array of response objects (a batch response)
            // This is for when findResponseById is called on a known batch array, like findResponseById($batchResponseArray, ...)
            // Or if $responsesToSearch contains a mix of single responses and batch arrays (though less common for $responsesToSearch itself)
            elseif (is_array($item)) {
                foreach ($item as $subItem) {
                    if (is_array($subItem) && isset($subItem['jsonrpc']) && isset($subItem['id'])) {
                        if ((string)$subItem['id'] === $idToFindStr) {
                            return $subItem;
                        }
                    }
                }
            }
        }
        return null;
    }

    public function testInitialization(): void
    {
        $initId = $this->_queueInitializeRequest();
        $shutdownId = 'shutdown_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->_findResponseById($actualResponses, $initId);

        $this->assertNotNull($initResponse, "Initialize response not found. Got: " . json_encode($rawOutput));
        // ... (rest of assertions remain the same)
        $this->assertEquals('2.0', $initResponse['jsonrpc']);
        $this->assertEquals($initId, $initResponse['id']);
        $this->assertArrayHasKey('result', $initResponse);
        $this->assertArrayHasKey('protocolVersion', $initResponse['result']);
        $this->assertEquals('2025-03-26', $initResponse['result']['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $initResponse['result']);
        $this->assertEquals('test-server', $initResponse['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $initResponse['result']);
        $this->assertArrayHasKey('test', $initResponse['result']['capabilities']);
        $this->assertEquals(['enabled' => true], $initResponse['result']['capabilities']['test']);
        $this->assertArrayHasKey('logging', $initResponse['result']['capabilities']);
        $this->assertEquals([], $initResponse['result']['capabilities']['logging']);
        $this->assertArrayHasKey('completions', $initResponse['result']['capabilities']);
        $this->assertEquals([], $initResponse['result']['capabilities']['completions']);
    }

    public function testCapabilityHandling(): void
    {
        $initId = $this->_queueInitializeRequest();
        $this->_capability->resetReceivedMessages();

        $capTestId = 'cap_test_id';
        $expectedResponse = JsonRpcMessage::result(['success' => true], $capTestId);
        $this->_capability->addExpectedResponse('test.method', $expectedResponse);

        $testRequest = ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['test' => true], 'id' => $capTestId];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_cap_handling_' . uniqid()];

        $this->_transport->writeToInput(json_encode($testRequest));
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->_findResponseById($actualResponses, $initId);
        $this->assertNotNull($initResponse, "Init response missing in capability test run. Raw: ".json_encode($rawOutput));

        $testMethodResponse = $this->_findResponseById($actualResponses, $capTestId);
        $this->assertNotNull($testMethodResponse, "Test method response not found. Raw: " . json_encode($rawOutput));
        if ($testMethodResponse) {
            $this->assertEquals($capTestId, $testMethodResponse['id']);
            $this->assertEquals(['success' => true], $testMethodResponse['result']);
        }

        $receivedCapabilityMessages = $this->_capability->getReceivedMessages();
        $this->assertCount(1, $receivedCapabilityMessages, "Capability should have received one message for 'test.method'.");
        $this->assertEquals('test.method', $receivedCapabilityMessages[0]->method);
    }

    public function testMethodNotFound(): void
    {
        $initId = $this->_queueInitializeRequest();
        $unknownId = 'unknown_id_' . uniqid();
        $unknownMethodRequest = ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'params' => [], 'id' => $unknownId];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_method_not_found_' . uniqid()];

        $this->_transport->writeToInput(json_encode($unknownMethodRequest));
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $errorResponse = $this->_findResponseById($actualResponses, $unknownId);
        $this->assertNotNull($errorResponse, "Error response for unknown method not found. Got: " . json_encode($rawOutput));
        if($errorResponse) {
            $this->assertEquals($unknownId, $errorResponse['id']);
            $this->assertArrayHasKey('error', $errorResponse);
            $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $errorResponse['error']['code']);
        }
    }

    private function _setEnvVar(string $name, string $value): void
    {
        putenv("$name=$value");
    }

    private function _clearEnvVar(string $name): void
    {
        putenv($name);
    }

    protected function tearDown(): void
    {
        $this->_clearEnvVar('MCP_AUTHORIZATION_TOKEN');
        parent::tearDown();
    }

    public function testBatchRequestProcessing(): void
    {
        $initId = $this->_queueInitializeRequest();
        $this->_capability->resetReceivedMessages();

        $batchId1 = 'batch_req_1_' . uniqid();
        $batchId2 = 'batch_req_2_' . uniqid();

        $batchRequests = [
            ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['data' => 'batch1'], 'id' => $batchId1],
            ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'id' => $batchId2],
            ['jsonrpc' => '2.0', 'method' => 'notify.method', 'params' => ['data' => 'notify1']]
        ];
        $this->_capability->addExpectedResponse('test.method', JsonRpcMessage::result(['received' => 'batch1'], $batchId1));

        $this->_transport->writeToInput(json_encode($batchRequests));
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_batch_id_' . uniqid()];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No output from server.");
        $this->assertIsArray($rawOutput[0], "Expected batch output from server not found or not an array.");

        $serverResponses = $rawOutput;

        // The server responds to a batch request with a batch response (single JSON array line)
        // This batch response itself is one of the items in $serverResponses, along with init and shutdown.
        // $serverResponses is [init_response, batch_array_response, shutdown_response]
        // So, the batch_array_response should be $serverResponses[1]

        $initResp = $this->_findResponseById($serverResponses, $initId); // Ensure init is found
        $this->assertNotNull($initResp, "Init response missing in batch test. Output: " . json_encode($serverResponses));

        $batchResponseArray = null;
        // Ensure serverResponses[1] exists, is an array, and is not a single JSON-RPC response object (i.e., it's a batch)
        if (isset($serverResponses[1]) && is_array($serverResponses[1]) && !isset($serverResponses[1]['jsonrpc'])) {
            $batchResponseArray = $serverResponses[1];
        }

        $this->assertNotNull($batchResponseArray, "Batch response array not found as serverResponses[1] or is not a batch. Output: " . json_encode($serverResponses));

        if($batchResponseArray) {
            $this->assertCount(2, $batchResponseArray, "Batch response should contain 2 items (1 result, 1 error)");

            $response1 = $this->_findResponseById($batchResponseArray, $batchId1);
            $this->assertNotNull($response1, "Response for $batchId1 not found in batch. Batch array: " .json_encode($batchResponseArray));
            if($response1) {
                $this->assertArrayHasKey('result', $response1);
                $this->assertEquals(['received' => 'batch1'], $response1['result']);
            }

            $response2 = $this->_findResponseById($batchResponseArray, $batchId2);
            $this->assertNotNull($response2, "Response for $batchId2 not found in batch. Batch array: " .json_encode($batchResponseArray));
            if($response2) {
                $this->assertArrayHasKey('error', $response2);
                $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $response2['error']['code']);
            }
        }
    }

    public function testAuthorizationRequiredAndSuccessful(): void
    {
        $this->_server->requireAuthorization("test_token_secret");
        $this->_setEnvVar('MCP_AUTHORIZATION_TOKEN', 'test_token_secret');

        $initId = $this->_queueInitializeRequest();
        $shutdownId = 'shutdown_auth_ok_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->_findResponseById($actualResponses, $initId);
        $this->assertNotNull($initResponse, "Initialize response not found. Raw: " . json_encode($rawOutput));
        $this->assertArrayHasKey('result', $initResponse, "Initialization should succeed with correct token.");
        $this->assertEquals($initId, $initResponse['id']);

        $this->_clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenMissing(): void
    {
        $this->_server->requireAuthorization("test_token_secret");
        $this->_clearEnvVar('MCP_AUTHORIZATION_TOKEN');

        $initId = $this->_queueInitializeRequest();
        $shutdownId = 'shutdown_auth_missing_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . json_encode($rawOutput));

        $errorResponse = $this->_findResponseById($actualResponses, $initId);
        $this->assertNotNull($errorResponse, "Error response for missing token not found. Got: " . json_encode($actualResponses));
        if($errorResponse) {
            $this->assertArrayHasKey('error', $errorResponse);
            $this->assertEquals(-32000, $errorResponse['error']['code']);
        }

        $this->_clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenInvalid(): void
    {
        $this->_server->requireAuthorization("test_token_secret");
        $this->_setEnvVar('MCP_AUTHORIZATION_TOKEN', 'wrong_token_secret');

        $initId = $this->_queueInitializeRequest();
        $shutdownId = 'shutdown_auth_invalid_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . json_encode($rawOutput));


        $errorResponse = $this->_findResponseById($actualResponses, $initId);
        $this->assertNotNull($errorResponse, "Error response for invalid token not found. Got: " . json_encode($actualResponses));
        if($errorResponse) {
            $this->assertArrayHasKey('error', $errorResponse);
            $this->assertEquals(-32001, $errorResponse['error']['code']);
        }

        $this->_clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testSetLogLevelRequest(): void
    {
        $initId = $this->_queueInitializeRequest();

        $setLevelRequestId = 'set_level_id_' . uniqid();
        $setLevelRequest = ['jsonrpc' => '2.0', 'method' => 'logging/setLevel', 'params' => ['level' => 'debug'], 'id' => $setLevelRequestId];
        $this->_transport->writeToInput(json_encode($setLevelRequest));

        $shutdownRequestId = 'shutdown_set_level_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownRequestId];
        $this->_transport->writeToInput(json_encode($shutdownRequest));

        $this->_server->run();
        $rawOutput = $this->_transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . json_encode($rawOutput));


        $setLevelResponse = $this->_findResponseById($actualResponses, $setLevelRequestId);
        $this->assertNotNull($setLevelResponse, "SetLevel response not found. Got: " . json_encode($actualResponses));
        if($setLevelResponse) {
            $this->assertArrayHasKey('result', $setLevelResponse);
            $this->assertEquals([], $setLevelResponse['result']);
        }
    }
}
