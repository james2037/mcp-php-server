<?php

namespace MCP\Server\Tests;

use PHPUnit\Framework\TestCase;
use MCP\Server\Server;
use MCP\Server\Capability\CapabilityInterface;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tests\Transport\TestableStdioTransport;
// TestCapability is now in a separate file.
use MCP\Server\Tests\TestCapability;
use MCP\Server\Capability\ResourcesCapability;
use MCP\Server\Tests\Transport\TestableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\ResponseFactory;
use MCP\Server\Exception\TransportException;
use MCP\Server\Transport\HttpTransport;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Tests\Capability\MockTool;
use MCP\Server\Exception\MethodNotSupportedException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \MCP\Server\Server
 */
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

    private function encodeAndAssert(mixed $data): string
    {
        $encoded = json_encode($data);
        self::assertIsString($encoded, 'JSON encoding failed for: ' . var_export($data, true));
        return $encoded;
    }

    private function queueInitializeRequest(string $protocolVersion = '2025-03-26'): string
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
        $this->transport->writeToInput($this->encodeAndAssert($initRequest));
        return $initRequestId;
    }

    /**
     * @param list<array<string, mixed>|list<array<string, mixed>>> $responsesToSearch Can be a list of single responses, or a list containing batch arrays.
     * @return array<string, mixed>|null
     */
    private function findResponseById(array $responsesToSearch, string $idToFind): ?array
    {
        // $idToFind is already a string, so no cast needed for $idToFindStr
        foreach ($responsesToSearch as $item) { // $item is typically a decoded JSON line
            // Case 1: $item is a single response object e.g. {'jsonrpc': ..., 'id': ...}
            if (is_array($item) && isset($item['jsonrpc']) && isset($item['id'])) {
                if ((string)$item['id'] === $idToFind) { // Use $idToFind directly
                    return $item;
                }
            } elseif (is_array($item)) { // Case 2: $item is an array of response objects (a batch response)
// This is for when findResponseById is called on a known batch array, like findResponseById($batchResponseArray, ...)
// Or if $responsesToSearch contains a mix of single responses and batch arrays (though less common for $responsesToSearch itself)
                foreach ($item as $subItem) {
                    if (is_array($subItem) && isset($subItem['jsonrpc']) && isset($subItem['id'])) {
                        if ((string)$subItem['id'] === $idToFind) { // Use $idToFind directly
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
        $initId = $this->queueInitializeRequest();
        $shutdownId = 'shutdown_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->findResponseById($actualResponses, $initId);

        $this->assertNotNull($initResponse, "Initialize response not found. Got: " . $this->encodeAndAssert($rawOutput));
        // $initResponse is now known to be non-null, PHPStan should infer its type as array<string, mixed>
        // from findResponseById's return type when not null.
        $this->assertEquals('2.0', $initResponse['jsonrpc']);
        $this->assertEquals($initId, $initResponse['id']);
        $this->assertArrayHasKey('result', $initResponse);
        self::assertIsArray($initResponse['result']); // Ensure result is an array before accessing its keys
        $this->assertArrayHasKey('protocolVersion', $initResponse['result']);
        $this->assertEquals('2025-03-26', $initResponse['result']['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $initResponse['result']);
        self::assertIsArray($initResponse['result']['serverInfo']); // Ensure serverInfo is an array
        $this->assertEquals('test-server', $initResponse['result']['serverInfo']['name']);
        $this->assertArrayHasKey('capabilities', $initResponse['result']);
        self::assertIsArray($initResponse['result']['capabilities']); // Ensure capabilities is an array
        $this->assertArrayHasKey('test', $initResponse['result']['capabilities']);
        $this->assertEquals(['enabled' => true], $initResponse['result']['capabilities']['test']);
        $this->assertArrayHasKey('logging', $initResponse['result']['capabilities']);
        $this->assertEquals([], $initResponse['result']['capabilities']['logging']);
        $this->assertArrayHasKey('completions', $initResponse['result']['capabilities']);
        $this->assertEquals([], $initResponse['result']['capabilities']['completions']);
    }

    public function testCapabilityHandling(): void
    {
        $initId = $this->queueInitializeRequest();
        $this->capability->resetReceivedMessages();

        $capTestId = 'cap_test_id';
        $expectedResponse = JsonRpcMessage::result(['success' => true], $capTestId);
        $this->capability->addExpectedResponse('test.method', $expectedResponse);

        $testRequest = ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['test' => true], 'id' => $capTestId];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_cap_handling_' . uniqid()];

        $this->transport->writeToInput($this->encodeAndAssert($testRequest));
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->findResponseById($actualResponses, $initId);
        $this->assertNotNull($initResponse, "Init response missing in capability test run. Raw: " . $this->encodeAndAssert($rawOutput));

        $testMethodResponse = $this->findResponseById($actualResponses, $capTestId);
        $this->assertNotNull($testMethodResponse, "Test method response not found. Raw: " . $this->encodeAndAssert($rawOutput));
        // $testMethodResponse is now non-null array
        $this->assertEquals($capTestId, $testMethodResponse['id']);
        $this->assertEquals(['success' => true], $testMethodResponse['result']);

        $receivedCapabilityMessages = $this->capability->getReceivedMessages();
        $this->assertCount(1, $receivedCapabilityMessages, "Capability should have received one message for 'test.method'.");
        $this->assertEquals('test.method', $receivedCapabilityMessages[0]->method);
    }

    public function testMethodNotFound(): void
    {
        $initId = $this->queueInitializeRequest();
        $unknownId = 'unknown_id_' . uniqid();
        $unknownMethodRequest = ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'params' => [], 'id' => $unknownId];
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_method_not_found_' . uniqid()];

        $this->transport->writeToInput($this->encodeAndAssert($unknownMethodRequest));
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $errorResponse = $this->findResponseById($actualResponses, $unknownId);
        $this->assertNotNull($errorResponse, "Error response for unknown method not found. Got: " . $this->encodeAndAssert($rawOutput));
        // $errorResponse is now non-null array
        $this->assertEquals($unknownId, $errorResponse['id']);
        $this->assertArrayHasKey('error', $errorResponse);
        self::assertIsArray($errorResponse['error']);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $errorResponse['error']['code']);
    }

    private function setEnvVar(string $name, string $value): void
    {
        putenv("$name=$value");
    }

    private function clearEnvVar(string $name): void
    {
        putenv($name);
    }

    protected function tearDown(): void
    {
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
        parent::tearDown();
    }

    public function testBatchRequestProcessing(): void
    {
        $initId = $this->queueInitializeRequest();
        $this->capability->resetReceivedMessages();

        $batchId1 = 'batch_req_1_' . uniqid();
        $batchId2 = 'batch_req_2_' . uniqid();

        $batchRequests = [
            ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['data' => 'batch1'], 'id' => $batchId1],
            ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'id' => $batchId2],
            ['jsonrpc' => '2.0', 'method' => 'notify.method', 'params' => ['data' => 'notify1']]
        ];
        $this->capability->addExpectedResponse('test.method', JsonRpcMessage::result(['received' => 'batch1'], $batchId1));

        $this->transport->writeToInput($this->encodeAndAssert($batchRequests));
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => 'shutdown_batch_id_' . uniqid()];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No output from server.");
        $this->assertIsArray($rawOutput[0], "Expected batch output from server not found or not an array.");

        $serverResponses = $rawOutput;

        // The server responds to a batch request with a batch response (single JSON array line)
        // This batch response itself is one of the items in $serverResponses, along with init and shutdown.
        // $serverResponses is [init_response, batch_array_response, shutdown_response]
        // So, the batch_array_response should be $serverResponses[1]

        $initResp = $this->findResponseById($serverResponses, $initId); // Ensure init is found
        $this->assertNotNull($initResp, "Init response missing in batch test. Output: " . $this->encodeAndAssert($serverResponses));

        $batchResponseArray = null;
        if (isset($serverResponses[1])) {
            self::assertIsArray($serverResponses[1], "serverResponses[1] should be an array (batch response).");
            $batchResponseArray = $serverResponses[1];
        }

        $this->assertNotNull($batchResponseArray, "Batch response array not found as serverResponses[1] or is not a batch. Output: " . $this->encodeAndAssert($serverResponses));
        // $batchResponseArray is now known to be a non-null array (of responses)

        $this->assertCount(2, $batchResponseArray, "Batch response should contain 2 items (1 result, 1 error)");

        /** @var list<array<string, mixed>> $batchResponseArray Ensure PHPStan knows this is a list of response objects. */
        $response1 = $this->findResponseById($batchResponseArray, $batchId1);
        $this->assertNotNull($response1, "Response for $batchId1 not found in batch. Batch array: " . $this->encodeAndAssert($batchResponseArray));
        // $response1 is non-null array
        $this->assertArrayHasKey('result', $response1);
        $this->assertEquals(['received' => 'batch1'], $response1['result']);

        /** @var list<array<string, mixed>> $batchResponseArray Ensure PHPStan knows this is a list of response objects for the next call too. */
        $response2 = $this->findResponseById($batchResponseArray, $batchId2);
        $this->assertNotNull($response2, "Response for $batchId2 not found in batch. Batch array: " . $this->encodeAndAssert($batchResponseArray));
        // $response2 is non-null array
        $this->assertArrayHasKey('error', $response2);
        self::assertIsArray($response2['error']);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $response2['error']['code']);
    }

    public function testAuthorizationRequiredAndSuccessful(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->setEnvVar('MCP_AUTHORIZATION_TOKEN', 'test_token_secret');

        $initId = $this->queueInitializeRequest();
        $shutdownId = 'shutdown_auth_ok_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();
        $actualResponses = $rawOutput;

        $initResponse = $this->findResponseById($actualResponses, $initId);
        $this->assertNotNull($initResponse, "Initialize response not found. Raw: " . $this->encodeAndAssert($rawOutput));
        // $initResponse is non-null array
        $this->assertArrayHasKey('result', $initResponse, "Initialization should succeed with correct token.");
        $this->assertEquals($initId, $initResponse['id']);

        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenMissing(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');

        $initId = $this->queueInitializeRequest();
        $shutdownId = 'shutdown_auth_missing_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . $this->encodeAndAssert($rawOutput));

        $errorResponse = $this->findResponseById($actualResponses, $initId);
        $this->assertNotNull($errorResponse, "Error response for missing token not found. Got: " . $this->encodeAndAssert($actualResponses));
        // $errorResponse is non-null array
        $this->assertArrayHasKey('error', $errorResponse);
        self::assertIsArray($errorResponse['error']);
        $this->assertEquals(-32000, $errorResponse['error']['code']);

        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testAuthorizationRequiredTokenInvalid(): void
    {
        $this->server->requireAuthorization("test_token_secret");
        $this->setEnvVar('MCP_AUTHORIZATION_TOKEN', 'wrong_token_secret');

        $initId = $this->queueInitializeRequest();
        $shutdownId = 'shutdown_auth_invalid_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . $this->encodeAndAssert($rawOutput));


        $errorResponse = $this->findResponseById($actualResponses, $initId);
        $this->assertNotNull($errorResponse, "Error response for invalid token not found. Got: " . $this->encodeAndAssert($actualResponses));
        // $errorResponse is non-null array
        $this->assertArrayHasKey('error', $errorResponse);
        self::assertIsArray($errorResponse['error']);
        $this->assertEquals(-32001, $errorResponse['error']['code']);

        $this->clearEnvVar('MCP_AUTHORIZATION_TOKEN');
    }

    public function testSetLogLevelRequest(): void
    {
        $initId = $this->queueInitializeRequest();

        $setLevelRequestId = 'set_level_id_' . uniqid();
        $setLevelRequest = ['jsonrpc' => '2.0', 'method' => 'logging/setLevel', 'params' => ['level' => 'debug'], 'id' => $setLevelRequestId];
        $this->transport->writeToInput($this->encodeAndAssert($setLevelRequest));

        $shutdownRequestId = 'shutdown_set_level_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownRequestId];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "No raw output from server.");
        $actualResponses = $rawOutput;
        $this->assertNotEmpty($actualResponses, "Server did not produce any responses. Raw: " . $this->encodeAndAssert($rawOutput));


        $setLevelResponse = $this->findResponseById($actualResponses, $setLevelRequestId);
        $this->assertNotNull($setLevelResponse, "SetLevel response not found. Got: " . $this->encodeAndAssert($actualResponses));
        // $setLevelResponse is non-null array
        $this->assertArrayHasKey('result', $setLevelResponse);
        $this->assertEquals([], $setLevelResponse['result']);
    }

    public function testServerCallsLifecycleMethodsOnCapabilities(): void
    {
        $server = new Server('test-lifecycle-server', '1.0.0');
        $transport = new TestableStdioTransport();

        $mockCapability = $this->createMock(CapabilityInterface::class);

        $mockCapability->expects($this->once())->method('initialize');
        $mockCapability->expects($this->once())->method('shutdown');
        // Provide a basic implementation for getCapabilities as the server calls it.
        $mockCapability->method('getCapabilities')->willReturn(['mockcap' => new \stdClass()]);

        $server->addCapability($mockCapability);
        $server->connect($transport);

        // Simulate server lifecycle
        $initRequestId = 'init_lifecycle_id_' . uniqid();
        $initRequest = [
            'jsonrpc' => '2.0', 'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                // No client capabilities needed if the mock doesn't use them for this test
            ],
            'id' => $initRequestId
        ];
        $transport->writeToInput($this->encodeAndAssert($initRequest));

        $shutdownRequestId = 'shutdown_lifecycle_id_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownRequestId];
        $transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $server->run();
        // PHPUnit automatically verifies mock expectations upon test completion.
    }

    /**
     * @param mixed $jsonData
     */
    private function createMockRequest(mixed $jsonData, string $method = 'POST', string $uri = '/'): ServerRequestInterface
    {
        $streamFactory = new StreamFactory();
        $body = is_string($jsonData) ? $jsonData : $this->encodeAndAssert($jsonData);
        $stream = $streamFactory->createStream($body);

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        return $request->withBody($stream)->withHeader('Content-Type', 'application/json');
    }

    public function testRunHttpRequestCycle(): void
    {
        // Server setup with TestableHttpTransport
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-server', '1.0.1');
        $capability = new TestCapability();
        $server->addCapability($capability);
        $server->connect($httpTransport);

        // Mock SAPI functions like headers_sent and http_response_code
        // This is tricky in unit tests. The SapiEmitter handles this.
        // For now, we'll focus on the ResponseInterface generated.
        // We assume SapiEmitter works correctly or is tested elsewhere.

        // Scenario 1: Single valid request (Initialize)
        $initRequestId = 'init_http_' . uniqid();
        $initRequestPayload = [
            'jsonrpc' => '2.0', 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'], 'id' => $initRequestId
        ];
        $mockRequest = $this->createMockRequest($initRequestPayload);
        $httpTransport->setMockRequest($mockRequest);

        // No actual output is emitted in tests, SapiEmitter is bypassed by not being in SAPI env
        // or by headers_sent being true (which we can't easily mock here without PECL functions)
        // The key is that $httpTransport->getResponse() will contain what *would* be emitted.

        // Suppress output from SapiEmitter if it tries to run
        // One way is to ensure headers are "sent"
        // @runInSeparateProcess might be needed if SapiEmitter is hard to control
        // However, Server::runHttpRequestCycle has a `if (!headers_sent())` guard.
        // In CLI tests, headers_sent() usually returns true if any output has occurred,
        // or false if run strictly. Let's assume it allows us to capture.

        $server->run(); // This will execute runHttpRequestCycle

        $capturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedResponse);
        $this->assertEquals(200, $capturedResponse->getStatusCode());
        $this->assertStringContainsString('application/json', $capturedResponse->getHeaderLine('Content-Type'));
        $responseBody = json_decode((string) $capturedResponse->getBody(), true);
        self::assertIsArray($responseBody); // Ensure $responseBody is an array
        $this->assertEquals($initRequestId, $responseBody['id']);
        $this->assertArrayHasKey('result', $responseBody);
        self::assertIsArray($responseBody['result']); // Ensure result is an array
        $this->assertEquals('2025-03-26', $responseBody['result']['protocolVersion']);

        // Scenario 2: Batch valid request
        $capability->resetReceivedMessages();
        $batchId1 = 'batch_http_1';
        $batchMethod1 = 'test.methodA'; // Changed
        $batchId2 = 'batch_http_2';
        $batchMethod2 = 'test.methodB'; // Changed

        $batchRequestPayload = [
            ['jsonrpc' => '2.0', 'method' => $batchMethod1, 'params' => ['data' => 'batch1'], 'id' => $batchId1],
            ['jsonrpc' => '2.0', 'method' => $batchMethod2, 'params' => ['data' => 'batch2'], 'id' => $batchId2],
        ];
        // Add expected responses for the new methods
        $capability->addExpectedResponse($batchMethod1, JsonRpcMessage::result(['received' => 'batch1'], $batchId1));
        $capability->addExpectedResponse($batchMethod2, JsonRpcMessage::result(['received' => 'batch2'], $batchId2));

        $mockBatchRequest = $this->createMockRequest($batchRequestPayload);
        $httpTransport->setMockRequest($mockBatchRequest);
        $server->run();
        $capturedBatchResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedBatchResponse);
        $this->assertEquals(200, $capturedBatchResponse->getStatusCode());
        $batchResponseBody = json_decode((string) $capturedBatchResponse->getBody(), true);
        self::assertIsArray($batchResponseBody); // Ensure $batchResponseBody is an array
        $this->assertCount(2, $batchResponseBody);
        self::assertIsArray($batchResponseBody[0]); // Ensure element is an array
        $this->assertEquals($batchId1, $batchResponseBody[0]['id']);
        $this->assertEquals(['received' => 'batch1'], $batchResponseBody[0]['result']);
        self::assertIsArray($batchResponseBody[1]); // Ensure element is an array
        $this->assertEquals($batchId2, $batchResponseBody[1]['id']);
        $this->assertEquals(['received' => 'batch2'], $batchResponseBody[1]['result']);


        // Scenario 3: Request causing TransportException (e.g., malformed JSON)
        // The TestableHttpTransport::receive() throws TransportException for malformed JSON.
        $mockMalformedRequest = $this->createMockRequest("this is not json");
        $httpTransport->setMockRequest($mockMalformedRequest);
        // Server's runHttpRequestCycle catches TransportException and creates an error response.
        $server->run();
        $capturedMalformedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedMalformedResponse);
        // HttpTransport now sends JSON-RPC errors with HTTP 200
        $this->assertEquals(200, $capturedMalformedResponse->getStatusCode());
        $malformedBody = json_decode((string) $capturedMalformedResponse->getBody(), true);
        self::assertIsArray($malformedBody); // Ensure $malformedBody is an array
        $this->assertArrayHasKey('error', $malformedBody);
        self::assertIsArray($malformedBody['error']);
        $this->assertEquals(JsonRpcMessage::PARSE_ERROR, $malformedBody['error']['code']);
        $this->assertNull($malformedBody['id']);


        // Scenario 4: Request for a method not found
        // Need to initialize server first before sending capability messages
        $httpTransport->setMockRequest($this->createMockRequest($initRequestPayload));
        $server->run(); // Initialize

        $unknownMethodId = 'unknown_http_' . uniqid();
        $unknownMethodPayload = ['jsonrpc' => '2.0', 'method' => 'unknown.method', 'id' => $unknownMethodId];
        $mockUnknownMethodRequest = $this->createMockRequest($unknownMethodPayload);
        $httpTransport->setMockRequest($mockUnknownMethodRequest);
        $server->run();
        $capturedUnknownResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedUnknownResponse);
        // HttpTransport now sends JSON-RPC errors with HTTP 200
        $this->assertEquals(200, $capturedUnknownResponse->getStatusCode());
        $unknownBody = json_decode((string) $capturedUnknownResponse->getBody(), true);
        self::assertIsArray($unknownBody); // Ensure $unknownBody is an array
        $this->assertEquals($unknownMethodId, $unknownBody['id']);
        $this->assertArrayHasKey('error', $unknownBody);
        self::assertIsArray($unknownBody['error']);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $unknownBody['error']['code']);

        // Scenario 5: TransportException set explicitly to be thrown by receive()
        $transportExceptionId = 'tex_http_' . uniqid();
        $someValidPayload = ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26'], 'id' => $transportExceptionId];
        $mockRequestForTransportException = $this->createMockRequest($someValidPayload);
        $httpTransport->setMockRequest($mockRequestForTransportException);
        $customTransportException = new TransportException("Custom transport error", 12345);
        $httpTransport->setExceptionToThrowOnReceive($customTransportException);

        $server->run();
        $capturedCustomErrorResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedCustomErrorResponse);
        // The status code will depend on how HttpTransport maps generic TransportException codes.
        // If the code is non-standard JSON-RPC, it might default to 500 or use the code if it's a valid HTTP code.
        // Server::runHttpRequestCycle catches TransportException and uses its code for JsonRpcMessage::error
        // HttpTransport then maps this JsonRpcMessage error code to an HTTP status.
        // If TransportException code 12345 is used in JsonRpcMessage, and it's not a standard one,
        // JsonRpcMessage::error would make it the error code.
        // HttpTransport now sends JSON-RPC errors with HTTP 200
        $this->assertEquals(200, $capturedCustomErrorResponse->getStatusCode());
        $customErrorBody = json_decode((string) $capturedCustomErrorResponse->getBody(), true);
        self::assertIsArray($customErrorBody); // Ensure $customErrorBody is an array
        $this->assertNull($customErrorBody['id']); // ID might be lost if error happens before parsing ID.
                                                 // Server.php L203 tries to get ID from raw payload for general errors.
                                                 // TransportException happens before this, so ID is null.
        $this->assertArrayHasKey('error', $customErrorBody);
        self::assertIsArray($customErrorBody['error']);
        $this->assertEquals(12345, $customErrorBody['error']['code']);
        $this->assertEquals("Custom transport error", $customErrorBody['error']['message']);
    }

    public function testInitializeAndToolCallInSameStream(): void
    {
        $server = new Server('test-stream-server', '1.0.0');
        $transport = new TestableStdioTransport();
        $toolsCapability = new ToolsCapability();
        $mockTool = new MockTool(); // Name 'test' and args are defined by attributes
        $toolsCapability->addTool($mockTool);
        $server->addCapability($toolsCapability);
        $server->connect($transport);

        $initId = 'init_stream_' . uniqid();
        $toolCallId = 'tool_call_stream_' . uniqid();

        $initRequest = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'],
            'id' => $initId
        ];

        $toolCallRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'test', 'arguments' => ['data' => 'stream_data']],
            'id' => $toolCallId
        ];

        $inputStream = json_encode($initRequest) . "\n" . json_encode($toolCallRequest) . "\n";

        $transport->writeToInput($inputStream);
        // We also need a shutdown request, otherwise the server might loop indefinitely
        // waiting for more input or a shutdown signal, depending on its run loop implementation.
        $shutdownId = 'shutdown_stream_' . uniqid();
        $shutdownRequest = ['jsonrpc' => '2.0', 'method' => 'shutdown', 'id' => $shutdownId];
        $transport->writeToInput(json_encode($shutdownRequest) . "\n");


        $server->run(); // This will process all messages in the input buffer

        $rawOutput = $transport->readMultipleJsonOutputs();

        // Expect 3 responses: init, tool call, shutdown
        $this->assertCount(3, $rawOutput, "Expected 3 responses (init, tool_call, shutdown). Got: " . json_encode($rawOutput));

        $initResponse = $this->findResponseById($rawOutput, $initId);
        $this->assertNotNull($initResponse, "Initialize response not found. Raw output: " . json_encode($rawOutput));
        self::assertIsArray($initResponse['result']);
        $this->assertArrayHasKey('protocolVersion', $initResponse['result'], "Initialize response should have protocolVersion.");

        $toolCallResponse = $this->findResponseById($rawOutput, $toolCallId);
        $this->assertNotNull($toolCallResponse, "Tool call response not found. Raw output: " . json_encode($rawOutput));
        $this->assertArrayNotHasKey('error', $toolCallResponse, "Tool call response should not have a top-level error field.");
        $this->assertArrayHasKey('result', $toolCallResponse, "Tool call response should have a result field.");
        self::assertIsArray($toolCallResponse['result']);
        $this->assertArrayHasKey('isError', $toolCallResponse['result'], "Tool call result should have 'isError' field.");
        $this->assertFalse($toolCallResponse['result']['isError'], "Tool call 'isError' should be false.");
        $this->assertArrayHasKey('content', $toolCallResponse['result'], "Tool call result should have 'content' field.");
        self::assertIsArray($toolCallResponse['result']['content']);
        $this->assertCount(1, $toolCallResponse['result']['content'], "Tool call content should have one item.");
        $this->assertEquals(['type' => 'text', 'text' => 'Result: stream_data'], $toolCallResponse['result']['content'][0], "Tool call content mismatch.");

        $shutdownResponse = $this->findResponseById($rawOutput, $shutdownId);
        $this->assertNotNull($shutdownResponse, "Shutdown response not found. Raw output: " . json_encode($rawOutput));
    }

    public function testMethodNotSupportedByCapabilityOverHttp(): void
    {
        // Server setup with TestableHttpTransport
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-server-method-not-supported', '1.0.0');

        // Create a mock capability
        /** @var CapabilityInterface&MockObject $mockCapability */
        $mockCapability = $this->createMock(CapabilityInterface::class);
        $unsupportedMethodName = 'test/unsupportedMethod'; // This is the fully qualified method name

        // This capability "knows" of "unsupportedMethod" but will throw an exception for it.
        // The key for getCapabilities should be the method name without the namespace,
        // as the server seems to merge these based on capability registration.
        // However, for a direct mock, we align what `getCapabilities` returns with what `canHandleMessage` expects.
        // Let's assume for now that the server will correctly use the full method name from the request
        // to check against `canHandleMessage`. The `Server::getServerCapabilitiesArray` merges these.
        // For this test, the important part is that `canHandleMessage` and `handleMessage` work correctly.
        $mockCapability->method('getCapabilities')->willReturn([
            $unsupportedMethodName => (object)['description' => 'A method that is known but not supported.']
        ]);

        $expectedException = new MethodNotSupportedException($unsupportedMethodName);

        // Configure 'canHandleMessage'
        $mockCapability->method('canHandleMessage')
            ->with($this->callback(function ($message) use ($unsupportedMethodName) {
                return $message instanceof JsonRpcMessage && $message->method === $unsupportedMethodName;
            }))
            ->willReturn(true);

        // Configure 'handleMessage' to throw MethodNotSupportedException for the specific method
        $mockCapability->method('handleMessage')
            ->with($this->callback(function ($message) use ($unsupportedMethodName) {
                return $message instanceof JsonRpcMessage && $message->method === $unsupportedMethodName;
            }))
            ->willThrowException($expectedException);

        // Mock other required methods from CapabilityInterface
        // For void return types, no ->willReturn() is needed if it should just do nothing.
        $mockCapability->method('initialize');
        $mockCapability->method('shutdown');

        $server->addCapability($mockCapability);
        $server->connect($httpTransport);

        // 1. Initialize the server
        $initRequestId = 'init_http_mns_' . uniqid();
        $initRequestPayload = [
            'jsonrpc' => '2.0', 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'], 'id' => $initRequestId
        ];
        $mockInitRequest = $this->createMockRequest($initRequestPayload);
        $httpTransport->setMockRequest($mockInitRequest);
        $server->run(); // Process initialization

        $initResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($initResponse, "Initialization response should not be null.");
        $this->assertEquals(200, $initResponse->getStatusCode(), "Initialization should return HTTP 200.");
        $initResponseBody = json_decode((string) $initResponse->getBody(), true);
        self::assertIsArray($initResponseBody);
        $this->assertEquals($initRequestId, $initResponseBody['id'], "Initialization response ID mismatch.");
        $this->assertArrayHasKey('result', $initResponseBody, "Successful initialization should have a result.");


        // 2. Send the request for the unsupported method
        $unsupportedMethodRequestId = 'unsupported_method_http_' . uniqid();
        $unsupportedMethodPayload = [
            'jsonrpc' => '2.0',
            'method' => $unsupportedMethodName,
            'params' => ['some_param' => 'some_value'],
            'id' => $unsupportedMethodRequestId
        ];
        $mockUnsupportedMethodRequest = $this->createMockRequest($unsupportedMethodPayload);
        $httpTransport->setMockRequest($mockUnsupportedMethodRequest);

        $server->run(); // Process the unsupported method request

        $capturedErrorResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedErrorResponse, "Error response for unsupported method should not be null.");

        // As per HttpTransport behavior, JSON-RPC errors are returned with HTTP 200
        // The distinction for MethodNotSupported (which implies server *could* handle it if method was supported)
        // vs MethodNotFound (server doesn't know method) might ideally be a 405 vs 404.
        // However, current Server implementation maps MethodNotSupportedException to JsonRpcMessage::METHOD_NOT_FOUND code (-32601)
        // And HttpTransport sends all JsonRpc errors with HTTP 200.
        // So, we expect HTTP 200 and JSON-RPC error -32601.
        // If the requirement is strict HTTP 405, then Server.php and HttpTransport.php would need changes.
        // For now, testing existing behavior.

        $this->assertEquals(200, $capturedErrorResponse->getStatusCode(), "HTTP status for MethodNotSupportedException should be 200 (as per current HttpTransport behavior).");

        $errorBody = json_decode((string) $capturedErrorResponse->getBody(), true);
        self::assertIsArray($errorBody, "Error response body should be a JSON array.");
        $this->assertEquals($unsupportedMethodRequestId, $errorBody['id'], "Error response ID should match request ID.");
        $this->assertArrayHasKey('error', $errorBody, "Error response should contain an 'error' object.");
        self::assertIsArray($errorBody['error'], "The 'error' field should be an array.");
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $errorBody['error']['code'], "JSON-RPC error code should be METHOD_NOT_FOUND for MethodNotSupportedException.");
        $this->assertStringContainsString($unsupportedMethodName, $errorBody['error']['message'], "Error message should contain the method name.");
    }

    public function testRunHandlesNonJsonRpcMessageInBatch(): void
    {
        $server = new Server('test-mixed-batch-server', '1.0.0');
        $transport = new TestableStdioTransport();
        $capability = new TestCapability();

        $server->addCapability($capability);
        $server->connect($transport);

        // 1. Queue Initialize request
        $initRequestId = 'init_mixed_batch_' . uniqid();
        // StdioTransport::receive() typically returns an array containing a single JsonRpcMessage.
        $initMessage = new JsonRpcMessage('initialize', ['protocolVersion' => '2025-03-26'], $initRequestId);
        $transport->queueReceiveOverride([$initMessage]); // Pass as an array, as if from StdioTransport::receive

        // 2. Queue the mixed batch
        $validRpcId1 = 'valid_rpc_1_' . uniqid();
        $validRpcMessage1 = new JsonRpcMessage('test.method', ['data' => 'message1'], $validRpcId1);

        $nonRpcObject = new \stdClass();
        $nonRpcObject->foo = 'bar';

        $validRpcId2 = 'valid_rpc_2_' . uniqid();
        $validRpcMessage2 = new JsonRpcMessage('test.method', ['data' => 'message2'], $validRpcId2);

        $mixedBatch = [$validRpcMessage1, $nonRpcObject, $validRpcMessage2];
        $transport->queueReceiveOverride($mixedBatch);

        // 3. Queue null to stop the server loop
        $transport->queueReceiveOverride(null);

        $server->run();

        // Assertions:
        // A. Both valid messages were processed by TestCapability.
        //    The log "[ERROR] Server.run: Received non-JsonRpcMessage object in batch." confirms the invalid item was skipped.
        $receivedCapabilityMessages = $capability->getReceivedMessages();
        $this->assertCount(2, $receivedCapabilityMessages, "Capability should have received both valid messages.");
        // The assertCount above ensures this condition is met, so the if is redundant.
        $this->assertEquals($validRpcId1, $receivedCapabilityMessages[0]->id);
        $this->assertEquals($validRpcId2, $receivedCapabilityMessages[1]->id);

        // Test Goal: Ensure the server hits the 'if (!$currentMessage instanceof JsonRpcMessage)' check,
        // skips the invalid item, and correctly processes surrounding valid items in the batch.

        // B. Server should have sent back:
        //    1. The init response.
        //    2. A batch response containing results for $validRpcMessage1 and $validRpcMessage2.
        $output = $transport->readMultipleJsonOutputs();
        $this->assertCount(2, $output, "Should have 2 output items: init response and one batch response. Actual output: " . json_encode($output));

        $initResponse = $this->findResponseById($output, $initRequestId); // findResponseById checks top-level and also inside batches
        $this->assertNotNull($initResponse, "Init response not found in output: " . json_encode($output));
        if ($initResponse) {
            $this->assertArrayHasKey('result', $initResponse, "Init response should be a success.");
        }

        // Find the batch response payload (it will be one of the items in $output)
        $batchResponsePayload = null;
        foreach ($output as $outItem) {
            if (!is_array($outItem)) {
                continue;
            }

            // To identify the batch response array within $output:
            // A batch response is a numerically indexed array (list) of response objects (arrays).
            // A single response is an associative array (map).
            // We check for the existence of key 0 to distinguish a list from a map.
            // PHPStan struggles with this if $outItem is inferred as array<string,mixed> from other contexts,
            // incorrectly assuming array_key_exists(0,...) must always be false.
            // @phpstan-ignore-next-line
            if (array_key_exists(0, $outItem)) {
                // This $outItem is potentially a batch (list of responses).
                // Ensure its first element is also an array (a response object).
                if (is_array($outItem[0])) {
                    // Now we're reasonably sure $outItem is a list of responses.
                    // For this specific test, we expect a batch of 2.
                    // @phpstan-ignore-next-line - PHPStan infers $outItem as *NEVER* here due to earlier ignored line.
                    if (count($outItem) === 2) {
                        // Verify it's the batch containing the specific responses we're looking for.
                        $response1 = $this->findResponseById($outItem, $validRpcId1);
                        $response2 = $this->findResponseById($outItem, $validRpcId2);

                        if ($response1 !== null && $response2 !== null) {
                            $batchResponsePayload = $outItem;
                            break; // Found the target batch
                        }
                    }
                }
            }
            // If key 0 does not exist, $outItem is treated as a single associative response,
            // not the batch of two responses we are searching for in this test.
        }
        // @phpstan-ignore-next-line - PHPStan infers $batchResponsePayload as null due to confusion above.
        $this->assertNotNull($batchResponsePayload, "Batch response payload for the two valid messages not found. Output: " . json_encode($output));
        // If $batchResponsePayload is not null, it must be the batch array.
        if ($batchResponsePayload) {
            // @phpstan-ignore-next-line - PHPStan still infers $batchResponsePayload as *NEVER* here.
            $this->assertCount(2, $batchResponsePayload, "Batch response should contain 2 results.");
            $response1 = $this->findResponseById($batchResponsePayload, $validRpcId1);
            $this->assertNotNull($response1, "Response for $validRpcId1 not found in batch.");
            if ($response1) { // Guard for runtime safety and type narrowing
                $this->assertArrayHasKey('result', $response1);
            }

            $response2 = $this->findResponseById($batchResponsePayload, $validRpcId2);
            $this->assertNotNull($response2, "Response for $validRpcId2 not found in batch.");
            if ($response2) { // Guard for runtime safety and type narrowing
                $this->assertArrayHasKey('result', $response2);
            }
        }


        // C. Check receive call count
        // Call 1: Init message (queued as array [initMessage])
        // Call 2: Mixed batch (queued)
        // Call 3: Null (queued to stop) -> leads to continue in Server::run loop if transport not 'closed'
        // Call 4: parent::receive() called, returns null, transport 'closed' -> loop breaks
        $this->assertEquals(4, $transport->getReceiveCallCount());
    }

    public function testRunCatchesThrowableFromTransportReceive(): void
    {
        $server = new Server('test-receive-exception-server', '1.0.0');
        $transport = new TestableStdioTransport();

        /** @var CapabilityInterface&MockObject $mockCapability */
        $mockCapability = $this->createMock(CapabilityInterface::class);
        $mockCapability->method('getCapabilities')->willReturn(['mockcap' => new \stdClass()]);
        $mockCapability->expects($this->once())->method('initialize');
        $mockCapability->expects($this->once())->method('shutdown'); // Key assertion for graceful shutdown

        $server->addCapability($mockCapability);
        $server->connect($transport);

        // 1. Queue Initialize request
        $initRequestId = 'init_receive_exception_' . uniqid();
        $initMessage = new JsonRpcMessage('initialize', ['protocolVersion' => '2025-03-26'], $initRequestId);
        $transport->queueReceiveOverride([$initMessage]);

        // 2. Configure transport to throw an exception on the next receive call
        $transport->throwOnNextReceive(new \RuntimeException("Simulated receive error"));

        // 3. Queue null to ensure the loop would terminate if the exception wasn't caught
        // and the server continued. This helps verify the exception handling leads to shutdown.
        $transport->queueReceiveOverride(null);

        // Server's run() method is expected to catch the RuntimeException.
        // If it's not caught, PHPUnit will fail the test due to an unhandled exception.
        $server->run();

        // Assertions:
        // 1. Mock capability's shutdown was called (verified by PHPUnit's mock expectations for $this->once()).
        // 2. Test completion without re-thrown exception implies it was caught by Server::run().
        // 3. Check receive call count.
        //    Call 1: Init message (queued).
        //    Call 2: Throws exception.
        //    In Server::run() (stdio loop), a generic \Throwable (like \RuntimeException) is caught,
        //    logged, but the loop continues. $this->shuttingDown is NOT set for generic Throwables.
        //    Call 3: The queued 'null' is processed by receive(). isClosed() is likely false, loop continues.
        //    Call 4: parent::receive() is called, returns null. isClosed() is true. Loop terminates.
        //    Therefore, receive() should be called 4 times.
        $this->assertEquals(4, $transport->getReceiveCallCount(), "Transport's receive() should be called for init, exception, queued null, and final parent::receive()->null.");
    }

    public function testRunHttpRequestCycleCatchesThrowableInBatchItemProcessing(): void
    {
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-batch-exception-server', '1.0.0');
        $capability = new TestCapability(); // For test.method
        $server->addCapability($capability);
        $server->connect($httpTransport);

        $initId = 'init_batch_ex_' . uniqid();
        $errorItemId = 'error_item_' . uniqid();
        $item3Id = 'item3_' . uniqid();

        $batchRequestPayload = [
            ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26'], 'id' => $initId],
            ['invalid_structure' => true, 'id' => $errorItemId], // This item should cause an exception
            ['jsonrpc' => '2.0', 'method' => 'test.method', 'params' => ['data' => 'item3_data'], 'id' => $item3Id]
        ];

        // TestCapability::handleMessage will provide a generic success response with the correct ID for 'test.method'.

        $mockRequest = $this->createMockRequest($batchRequestPayload);
        $httpTransport->setMockRequest($mockRequest);

        $server->run(); // This executes runHttpRequestCycle

        $capturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedResponse);
        $this->assertEquals(200, $capturedResponse->getStatusCode()); // JSON-RPC errors still use HTTP 200

        $responseBody = json_decode((string) $capturedResponse->getBody(), true);
        $this->assertIsArray($responseBody, "Response body should be a JSON array (batch response)");
        $this->assertCount(3, $responseBody, "Batch response should contain 3 items");

        // Find responses by ID (findResponseById can search within a batch if $responseBody is the batch itself)
        $initItemResponse = $this->findResponseById($responseBody, $initId);
        $errorItemResponse = $this->findResponseById($responseBody, $errorItemId);
        $item3Response = $this->findResponseById($responseBody, $item3Id);

        // Assert Initialize was successful
        $this->assertNotNull($initItemResponse, "Initialize response not found in batch.");
        if ($initItemResponse) {
            $this->assertArrayHasKey('result', $initItemResponse, "Initialize response should be a success.");
            $this->assertArrayNotHasKey('error', $initItemResponse, "Initialize response should not be an error.");
            // Ensure 'result' is an array before accessing sub-keys
            $this->assertIsArray($initItemResponse['result']);
            $this->assertEquals('2025-03-26', $initItemResponse['result']['protocolVersion']);
        }

        // Assert Malformed item resulted in an error
        $this->assertNotNull($errorItemResponse, "Error response for malformed item not found in batch.");
        if ($errorItemResponse) {
            $this->assertArrayHasKey('error', $errorItemResponse, "Malformed item should have an error response.");
            $this->assertArrayNotHasKey('result', $errorItemResponse, "Malformed item should not have a result.");
            // Ensure 'error' is an array before accessing sub-keys
            $this->assertIsArray($errorItemResponse['error']);
            $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $errorItemResponse['error']['code'], "Error code for malformed item processing should be INTERNAL_ERROR.");
            $this->assertStringContainsString("Error processing item in batch", $errorItemResponse['error']['message'], "Error message mismatch for malformed item.");
        }

        // Assert test.method was successful
        $this->assertNotNull($item3Response, "test.method response not found in batch.");
        if ($item3Response) {
            $this->assertArrayHasKey('result', $item3Response, "test.method response should be a success.");
            $this->assertArrayNotHasKey('error', $item3Response, "test.method response should not be an error.");
        }

        // Assert TestCapability received the call for test.method
        $receivedCapabilityMessages = $capability->getReceivedMessages();
        $this->assertCount(1, $receivedCapabilityMessages, "Capability should have received one message for 'test.method'.");
        // The assertCount above ensures $receivedCapabilityMessages is not empty.
        $this->assertEquals($item3Id, $receivedCapabilityMessages[0]->id);
        $this->assertEquals('test.method', $receivedCapabilityMessages[0]->method);
    }

    public function testRunHttpRequestCycleHandlesJsonEncodeFailureInSingleRequest(): void
    {
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-single-json-encode-fail', '1.0.0');
        // No capability needed as the failure happens before capability routing for a non-init method.
        $server->connect($httpTransport);

        $errorItemId = 'single_err_json_encode_' . uniqid();

        // This raw payload will be returned directly by TestableHttpTransport::receive()
        $rawPayload = [
            'jsonrpc' => '2.0',
            'method' => 'some_method', // Not 'initialize'
            'params' => ['bad_string' => "\xB1\x31"], // Bad UTF-8 string
            'id' => $errorItemId
        ];
        $httpTransport->setPreDecodedPayload($rawPayload);

        // Server::runHttpRequestCycle processes one request.
        // $rawPayload is a single request.
        // Inside runHttpRequestCycle, for a single request:
        //   try {
        //     $messageJson = json_encode($rawPayload); // This is expected to fail
        //     if ($messageJson === false) {
        //       throw new \RuntimeException("Failed to re-encode single request for parsing.", JsonRpcMessage::PARSE_ERROR);
        //     }
        //     // ... further processing ...
        //   } catch (\Throwable $e) {
        //     // The RuntimeException with PARSE_ERROR code will be caught here.
        //     // $errorCode will be $e->getCode() which is JsonRpcMessage::PARSE_ERROR.
        //     // $responsePayload = JsonRpcMessage::error($errorCode, ...);
        //   }
        // The server is not initialized when 'some_method' is called. However, the json_encode failure
        // happens before the server even checks for initialization for this specific method.
        // The primary error caught is the re-encoding failure.

        $server->run(); // Executes runHttpRequestCycle

        $capturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedResponse);
        $this->assertEquals(200, $capturedResponse->getStatusCode()); // JSON-RPC errors are sent with HTTP 200

        $responseBody = json_decode((string) $capturedResponse->getBody(), true);
        $this->assertIsArray($responseBody);
        $this->assertArrayNotHasKey(0, $responseBody, "Should be a single error response, not a batch.");

        $this->assertArrayHasKey('error', $responseBody);
        $this->assertNotNull($responseBody['error']);
        $this->assertEquals(JsonRpcMessage::PARSE_ERROR, $responseBody['error']['code']);
        $this->assertStringContainsString("Failed to re-encode single request for parsing.", $responseBody['error']['message']);
        $this->assertEquals($errorItemId, $responseBody['id']);
    }

    public function testRunHttpRequestCycleCatchesThrowableFromProcessSingleMessage(): void
    {
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-process-msg-ex', '1.0.0');

        /** @var CapabilityInterface&MockObject $mockCapability */
        $mockCapability = $this->createMock(CapabilityInterface::class);

        $server->addCapability($mockCapability);
        $server->connect($httpTransport);

        // --- Phase 1: Initialize Server ---
        $initId = 'init_process_msg_ex_' . uniqid();
        $initPayload = ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26'], 'id' => $initId];

        $mockCapability->method('getCapabilities')->willReturn(['mock_method' => (object)['description' => 'A mock method']]);
        $mockCapability->expects($this->once())->method('initialize');
        // Ensure canHandleMessage for 'initialize' is false so server handles it.
        // And true for 'mock_method' later.
        $mockCapability->method('canHandleMessage')
            ->willReturnCallback(function (JsonRpcMessage $message) {
                return $message->method === 'mock_method';
            });

        $httpTransport->setPreDecodedPayload($initPayload);
        $server->run(); // First run for initialization

        $initCapturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($initCapturedResponse);
        $this->assertEquals(200, $initCapturedResponse->getStatusCode());
        $initResponseBody = json_decode((string) $initCapturedResponse->getBody(), true);
        $this->assertNotNull($initResponseBody, "Init response body should not be null");
        if ($initResponseBody) {
            $this->assertArrayHasKey('result', $initResponseBody, "Initialize should succeed.");
        }


        // --- Phase 2: Send method call that causes capability to throw ---
        $mockMethodId = 'mock_method_ex_' . uniqid();
        $mockMethodPayload = ['jsonrpc' => '2.0', 'method' => 'mock_method', 'params' => ['foo' => 'bar'], 'id' => $mockMethodId];

        // Configure handleMessage to throw for 'mock_method'
        $mockCapability->method('handleMessage')
            ->with($this->callback(function (JsonRpcMessage $message) use ($mockMethodId) {
                return $message->method === 'mock_method' && $message->id === $mockMethodId;
            }))
            ->willThrowException(new \RuntimeException("Simulated capability error"));

        $httpTransport->setPreDecodedPayload($mockMethodPayload);
        $server->run(); // Second run for the method call

        $errorCapturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($errorCapturedResponse);
        $this->assertEquals(200, $errorCapturedResponse->getStatusCode());

        $errorResponseBody = json_decode((string) $errorCapturedResponse->getBody(), true);
        $this->assertIsArray($errorResponseBody);
        $this->assertArrayNotHasKey(0, $errorResponseBody, "Should be a single error response, not a batch.");

        $this->assertArrayHasKey('error', $errorResponseBody);
        $this->assertNotNull($errorResponseBody['error']);
        $this->assertIsArray($errorResponseBody['error']); // Ensure error is an array before accessing keys
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $errorResponseBody['error']['code']);
        $this->assertEquals("Simulated capability error", $errorResponseBody['error']['message']); // Exact message match
        $this->assertEquals($mockMethodId, $errorResponseBody['id']);
    }

    public function testRunHttpRequestCycleCatchesOuterThrowableFromTransportReceive(): void
    {
        $httpTransport = new TestableHttpTransport(new ResponseFactory(), new StreamFactory());
        $server = new Server('test-http-outer-ex-server', '1.0.0');
        // No capability is needed as the error occurs in the transport's receive method itself.
        $server->connect($httpTransport);

        $exceptionMessage = "Simulated transport receive critical error";
        $httpTransport->setExceptionToThrowOnReceive(new \RuntimeException($exceptionMessage));

        // No mock request needs to be set on TestableHttpTransport if receive() throws before using it.
        // However, TestableHttpTransport::receive() calls $this->getRequest() first if preDecodedPayload and exceptionToThrow are null.
        // The current TestableHttpTransport::receive() checks $exceptionToThrowOnReceive first. So this is fine.

        $server->run(); // Executes runHttpRequestCycle

        $capturedResponse = $httpTransport->getCapturedResponse();
        $this->assertNotNull($capturedResponse);
        $this->assertEquals(200, $capturedResponse->getStatusCode()); // JSON-RPC errors are sent with HTTP 200

        $responseBody = json_decode((string) $capturedResponse->getBody(), true);
        $this->assertIsArray($responseBody);
        $this->assertArrayNotHasKey(0, $responseBody, "Should be a single error response, not a batch.");

        $this->assertArrayHasKey('error', $responseBody);
        $this->assertNotNull($responseBody['error']);
        $this->assertIsArray($responseBody['error']);

        // Assertions for the generic "Internal server error" response
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $responseBody['error']['code']);
        $this->assertEquals('Internal server error.', $responseBody['error']['message']);
        $this->assertNull($responseBody['id']);

        // Implicitly, the test passing means the exception was caught and handled.
        // The log "Critical Server Error in HTTP cycle: Simulated transport receive critical error"
        // should have been made (verified by observing test output in previous runs for similar logs).
    }
}
