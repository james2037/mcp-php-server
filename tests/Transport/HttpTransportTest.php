<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Transport\HttpTransport;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

class HttpTransportTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    // Mock ServerRequestInterface and its StreamInterface body
    private ServerRequestInterface $mockRequest;
    private StreamInterface $mockStream;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockRequest = $this->createMock(ServerRequestInterface::class);
        $this->mockStream = $this->createMock(StreamInterface::class);

        // Default behavior for getBody() on the mock request
        $this->mockRequest->method('getBody')->willReturn($this->mockStream);
    }

    /**
     * Helper to create HttpTransport with mocked dependencies.
     * This now uses TestableHttpTransport to allow setting the mock request.
     */
    private function createTransport(?ServerRequestInterface $request = null): TestableHttpTransport
    {
        // If no request is provided, use the class property $this->mockRequest
        $testableTransport = new TestableHttpTransport(
            $this->psr17Factory,
            $this->psr17Factory,
            $request ?? $this->mockRequest // Pass the mock request to TestableHttpTransport constructor
        );
        // Ensure the mock request is properly set within TestableHttpTransport
        // if it wasn't passed via constructor or if we need to re-set/confirm it.
        $testableTransport->setMockRequest($request ?? $this->mockRequest);
        return $testableTransport;
    }


    public function testConstructor(): void
    {
        // Test with the default mockRequest from setUp
        $transport = $this->createTransport();
        $this->assertInstanceOf(HttpTransport::class, $transport);
        $response = $transport->getResponse();
        $this->assertInstanceOf(ResponseInterface::class, $response);
        // Test constructor with explicit null request (HttpTransport should create one from globals)
        // For unit testing, we avoid fromGlobals. This instance is not used further.
        $transportFromGlobals = new HttpTransport($this->psr17Factory, $this->psr17Factory, null);
        $this->assertInstanceOf(HttpTransport::class, $transportFromGlobals);
    }

    // --- Tests for receive() ---

    public function testReceiveValidSingleRequest(): void
    {
        $jsonRpcPayloadArray = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $jsonRpcString = json_encode($jsonRpcPayloadArray);

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn($jsonRpcString);

        $transport = $this->createTransport();
        $receivedMessages = $transport->receive();

        $this->assertIsArray($receivedMessages);
        $this->assertCount(1, $receivedMessages);
        $this->assertInstanceOf(JsonRpcMessage::class, $receivedMessages[0]);
        $this->assertEquals('test', $receivedMessages[0]->method);
        $this->assertEquals(1, $receivedMessages[0]->id);
    }

    public function testReceiveValidBatchRequest(): void
    {
        $jsonRpcPayloadArray = [
            ['jsonrpc' => '2.0', 'method' => 'test1', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'test2', 'id' => 2]
        ];
        $jsonRpcString = json_encode($jsonRpcPayloadArray);

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn($jsonRpcString);

        $transport = $this->createTransport();
        $receivedMessages = $transport->receive();

        $this->assertIsArray($receivedMessages);
        $this->assertCount(2, $receivedMessages);
        $this->assertInstanceOf(JsonRpcMessage::class, $receivedMessages[0]);
        $this->assertEquals('test1', $receivedMessages[0]->method);
        $this->assertInstanceOf(JsonRpcMessage::class, $receivedMessages[1]);
        $this->assertEquals('test2', $receivedMessages[1]->method);
    }

    public function testReceivePostRequestInvalidJsonSyntax(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessageMatches('/Failed to decode JSON/'); // From parseMessages

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('{"invalidjson');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestInvalidJsonRpcStructureNotObjectOrArray(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        // This message comes from parseMessages
        $this->expectExceptionMessageMatches('/Decoded JSON is not an array or object/');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('"just a string"');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestEmptyBodyReturnsNull(): void
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn(''); // Empty body

        $transport = $this->createTransport();
        $this->assertNull($transport->receive());
    }

    public function testReceivePostRequestWhitespaceBodyReturnsNull(): void
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('   '); // Whitespace body

        $transport = $this->createTransport();
        $this->assertNull($transport->receive());
    }

    public function testReceivePostRequestIncorrectContentTypeReturnsFalse(): void
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('text/plain');
        // Body content doesn't matter here as the Content-Type check should fail first
        $this->mockStream->method('__toString')->willReturn('{"jsonrpc":"2.0","method":"test"}');

        $transport = $this->createTransport();
        $this->assertFalse($transport->receive());
    }

    public function testReceivePostRequestApplicationJsonRpcContentTypeIsValid(): void
    {
        $jsonRpcPayloadArray = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $jsonRpcString = json_encode($jsonRpcPayloadArray);

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json-rpc');
        $this->mockStream->method('__toString')->willReturn($jsonRpcString);

        $transport = $this->createTransport();
        $receivedMessages = $transport->receive();
        $this->assertIsArray($receivedMessages);
        $this->assertCount(1, $receivedMessages);
        $this->assertInstanceOf(JsonRpcMessage::class, $receivedMessages[0]);
    }

    public function testReceivePostRequestMissingContentTypeReturnsFalse(): void
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn(''); // Missing
        $this->mockStream->method('__toString')->willReturn('{"jsonrpc":"2.0","method":"test"}');

        $transport = $this->createTransport();
        $this->assertFalse($transport->receive());
    }

    public function testReceiveNonPostRequestReturnsFalse(): void
    {
        $this->mockRequest->method('getMethod')->willReturn('GET');
        // Other mocks don't matter as method check is first

        $transport = $this->createTransport();
        $this->assertFalse($transport->receive());
    }

    // --- Tests for receive() an lastRequestAppearedAsBatch() ---

    /**
     * @dataProvider batchDetectionDataProvider
     */
    public function testLastRequestAppearedAsBatchDetection(string $requestBody, bool $expectedIsBatch): void
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn($requestBody);

        $transport = $this->createTransport();

        // Call receive to trigger parsing and setting of the flag
        try {
            $transport->receive();
        } catch (TransportException $e) {
            // Expected for malformed JSON, but flag should still be set based on initial structure
        }

        $this->assertSame($expectedIsBatch, $transport->lastRequestAppearedAsBatch());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function batchDetectionDataProvider(): array
    {
        return [
            'single object' => ['{"id":1,"method":"test"}', false],
            'single object with whitespace' => ['  {"id":1,"method":"test"}  ', false],
            'batch array' => ['[{"id":1,"method":"test"}]', true],
            'batch array with whitespace' => ['  [{"id":1,"method":"test"}]  ', true],
            'empty batch array' => ['[]', true],
            'empty batch array with whitespace' => ['  []  ', true],
            'malformed batch-like (missing closing bracket)' => ['[{"id":1}', false], // Does not end with ']'
            'malformed single-like (missing closing brace)' => ['{"id":1', false], // Does not end with '}'
            'simple string (not batch)' => ['"hello"', false],
            'empty string (not batch)' => ['', false],
            'whitespace string (not batch)' => ['   ', false],
        ];
    }

    // --- Tests for send() ---

    public function testSendSingleMessage(): void
    {
        $transport = $this->createTransport();
        $rpcResponse = JsonRpcMessage::result(['foo' => 'bar'], '1');

        $transport->send($rpcResponse);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $expectedJson = '{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}}';
        $this->assertJsonStringEqualsJsonString($expectedJson, (string) $response->getBody());
    }

    public function testSendBatchMessage(): void
    {
        $transport = $this->createTransport();
        $rpcResponses = [
            JsonRpcMessage::result(['foo' => 'bar'], '1'),
            JsonRpcMessage::error(JsonRpcMessage::INVALID_REQUEST, 'Invalid Request', '2')
        ];
        $transport->send($rpcResponses);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $expectedJson = '[{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}},{"jsonrpc":"2.0","id":"2","error":{"code":-32600,"message":"Invalid Request"}}]';
        $this->assertJsonStringEqualsJsonString($expectedJson, (string) $response->getBody());
    }

    // testSendNullMessageSimplified() is removed as send(null) is no longer a valid direct call based on refactored logic.

    public function testSendEmptyArrayMessage(): void
    {
        $transport = $this->createTransport();
        $transport->send([]); // Empty batch

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('[]', (string) $response->getBody());
    }

    public function testSendHandlesJsonEncodeFailureSimplified(): void
    {
        $transport = $this->createTransport();
        $problematicData = fopen('php://memory', 'r');

        // Create a real JsonRpcMessage whose result, when serialized by its jsonSerialize method,
        // will contain a resource, causing json_encode in HttpTransport::send to fail.
        $messageWithError = JsonRpcMessage::result(['unencodable' => $problematicData], 'encode-fail-id');

        $transport->send($messageWithError);

        if (is_resource($problematicData)) {
            fclose($problematicData);
        }

        $response = $transport->getResponse();
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertJson($body);
        $decodedBody = json_decode($body, true);
        $this->assertEquals('2.0', $decodedBody['jsonrpc']);
        $this->assertNull($decodedBody['id']); // ID is null because it couldn't be determined from the failed message
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $decodedBody['error']['code']);
        // This is the message set by HttpTransport::send when JsonRpcMessage::toJson/toJsonArray fails
        $this->assertEquals('Server error: Failed to serialize JSON response.', $decodedBody['error']['message']);
    }
}
