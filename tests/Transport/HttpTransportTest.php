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
    private ServerRequestInterface $mockRequest;
    private StreamInterface $mockStream;
    // private ResponseInterface $mockResponse; // Removed as it's unused


    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockRequest = $this->createMock(ServerRequestInterface::class);
        $this->mockStream = $this->createMock(StreamInterface::class);
        // $this->mockResponse = $this->createMock(ResponseInterface::class); // Removed

        // Default behavior for getBody
        $this->mockRequest->method('getBody')->willReturn($this->mockStream);
    }

    private function createTransport(): HttpTransport
    {
        return new HttpTransport(
            $this->psr17Factory,      // Corrected: ResponseFactoryInterface
            $this->psr17Factory,      // Corrected: StreamFactoryInterface
            $this->mockRequest        // Corrected: ServerRequestInterface
        );
    }

    public function testConstructor(): void
    {
        $transport = $this->createTransport();
        $this->assertInstanceOf(HttpTransport::class, $transport);
        // Test that getResponse() returns the initially created (empty) response
        $response = $transport->getResponse();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    // --- Tests for receive() ---

    public function testReceiveValidSinglePostRequestSimplified(): void
    {
        $jsonRpcPayload = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $jsonRpcString = json_encode($jsonRpcPayload);

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn($jsonRpcString);

        $transport = $this->createTransport();
        $receivedData = $transport->receive();

        $this->assertEquals($jsonRpcPayload, $receivedData);
    }

    public function testReceiveValidBatchPostRequestSimplified(): void
    {
        $jsonRpcPayload = [
            ['jsonrpc' => '2.0', 'method' => 'test1', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'test2', 'id' => 2]
        ];
        $jsonRpcString = json_encode($jsonRpcPayload);

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn($jsonRpcString);

        $transport = $this->createTransport();
        $receivedData = $transport->receive();

        $this->assertEquals($jsonRpcPayload, $receivedData);
    }

    public function testReceivePostRequestInvalidJsonSyntaxSimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessageMatches('/JSON Parse Error/');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('{"invalidjson');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestInvalidJsonRpcStructureSimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Invalid JSON-RPC: Request must be a JSON object or an array of JSON objects.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('"just a string"');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestEmptyBodySimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Request body cannot be empty for JSON-RPC POST.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $this->mockStream->method('__toString')->willReturn('');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestIncorrectContentTypeSimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Content-Type must be application/json.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('text/plain');
        $this->mockStream->method('__toString')->willReturn('{"jsonrpc":"2.0","method":"test"}');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestMissingContentTypeSimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Content-Type must be application/json.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->with('Content-Type')->willReturn('');
        $this->mockStream->method('__toString')->willReturn('{"jsonrpc":"2.0","method":"test"}');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceiveNonPostRequestSimplified(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Only POST requests are supported.');

        $this->mockRequest->method('getMethod')->willReturn('GET');

        $transport = $this->createTransport();
        $transport->receive();
    }

    // --- Tests for send() ---

    public function testSendSingleMessageSimplified(): void
    {
        $transport = $this->createTransport();
        // Create a real JsonRpcMessage, assuming JsonRpcMessage class is available and works
        $rpcResponse = JsonRpcMessage::result(['foo' => 'bar'], '1');

        $transport->send($rpcResponse);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $expectedJson = '{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}}';
        // Note: The order of keys in actual JSON might vary. assertJsonStringEqualsJsonString handles this.
        // Also, JsonRpcMessage::jsonSerialize() implementation detail matters here.
        $this->assertJsonStringEqualsJsonString($expectedJson, (string) $response->getBody());
    }

    public function testSendBatchMessageSimplified(): void
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

    public function testSendNullMessageSimplified(): void
    {
        $transport = $this->createTransport();
        $transport->send(null);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('null', (string) $response->getBody());
    }

    public function testSendEmptyBatchMessageSimplified(): void
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
        $this->assertNull($decodedBody['id']);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $decodedBody['error']['code']);
        $this->assertStringContainsString('Failed to encode JSON response', $decodedBody['error']['message']);
    }
}
