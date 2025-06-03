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

    public function testInitializeWithNullIdReturnsError(): void
    {
        $initRequest = [
            "jsonrpc" => "2.0", "method" => "initialize",
            "params" => ["protocolVersion" => "2025-03-26"],
            "id" => null
        ];
        $this->transport->writeToInput($this->encodeAndAssert($initRequest));
        $shutdownRequest = ["jsonrpc" => "2.0", "method" => "shutdown", "id" => "shutdown_null_init_" . uniqid()];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertNotEmpty($rawOutput, "Server produced no output.");
        $errorResponse = (isset($rawOutput[0]) && is_array($rawOutput[0]) && isset($rawOutput[0][0])) ? $rawOutput[0][0] : null;

        $this->assertNotNull($errorResponse, "Error response for null ID init not found.");
        $this->assertArrayHasKey("error", $errorResponse);
        self::assertIsArray($errorResponse["error"]);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $errorResponse["error"]["code"]);
        $this->assertStringContainsStringIgnoringCase("request is missing an id", $errorResponse["error"]["message"]);
        $this->assertNull($errorResponse["id"]);
    }

    public function testShutdownWithNullIdReturnsError(): void
    {
        $this->queueInitializeRequest();

        $shutdownRequest = ["jsonrpc" => "2.0", "method" => "shutdown", "id" => null];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertCount(2, $rawOutput, "Expected init and shutdown responses. Got: " . json_encode($rawOutput));
        $errorResponse = (isset($rawOutput[1]) && is_array($rawOutput[1]) && isset($rawOutput[1][0])) ? $rawOutput[1][0] : null;

        $this->assertNotNull($errorResponse, "Error response for null ID shutdown not found.");
        $this->assertArrayHasKey("error", $errorResponse);
        self::assertIsArray($errorResponse["error"]);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $errorResponse["error"]["code"]);
        $this->assertStringContainsStringIgnoringCase("request is missing an id", $errorResponse["error"]["message"]);
        $this->assertNull($errorResponse["id"]);
    }

    public function testSetLogLevelWithNullIdReturnsError(): void
    {
        $this->queueInitializeRequest();

        $setLevelRequest = ["jsonrpc" => "2.0", "method" => "logging/setLevel", "params" => ["level" => "debug"], "id" => null];
        $this->transport->writeToInput($this->encodeAndAssert($setLevelRequest));
        $shutdownRequest = ["jsonrpc" => "2.0", "method" => "shutdown", "id" => "shutdown_null_log_" . uniqid()];
        $this->transport->writeToInput($this->encodeAndAssert($shutdownRequest));

        $this->server->run();
        $rawOutput = $this->transport->readMultipleJsonOutputs();

        $this->assertCount(4, $rawOutput, "Expected init, logging notification, setLevel error, and shutdown responses. Got: " . json_encode($rawOutput));

        $setLevelErrorResponse = (isset($rawOutput[2]) && is_array($rawOutput[2]) && isset($rawOutput[2][0])) ? $rawOutput[2][0] : null;

        $this->assertNotNull($setLevelErrorResponse, "Error response for null ID setLevel not found. Raw output: " . json_encode($rawOutput));
        $this->assertArrayHasKey("error", $setLevelErrorResponse);
        self::assertIsArray($setLevelErrorResponse["error"]);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $setLevelErrorResponse["error"]["code"]);
        $this->assertStringContainsStringIgnoringCase("SetLogLevel request is missing an ID.", $setLevelErrorResponse["error"]["message"]);
        $this->assertNull($setLevelErrorResponse["id"]);
    }
}
