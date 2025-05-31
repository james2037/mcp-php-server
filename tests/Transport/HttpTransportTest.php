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
use Nyholm\Psr7\Factory\Psr17Factory; // Using Nyholm as a concrete factory for tests

class HttpTransportTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private ServerRequestInterface $mockRequest;
    private StreamInterface $mockStream;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockRequest = $this->createMock(ServerRequestInterface::class);
        $this->mockStream = $this->createMock(StreamInterface::class);
        $this->mockRequest->method('getBody')->willReturn($this->mockStream);
    }

    private function createTransport(array $allowedOrigins = []): HttpTransport
    {
        // Ensure getUri() is mocked if Origin validation relies on it (not directly in these tests but good practice)
        $mockUri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $this->mockRequest->method('getUri')->willReturn($mockUri);
        // $mockUri->method('getHost')->willReturn('test.host'); // Example if needed

        return new HttpTransport(
            $this->mockRequest,
            $this->psr17Factory,
            $this->psr17Factory,
            $allowedOrigins
        );
    }

    private function getResponseContent(HttpTransport $transport): array
    {
        $response = $transport->getResponse();
        return json_decode((string) $response->getBody(), true);
    }

    // --- Tests for receive() ---

    public function testReceiveSinglePostRequest()
    {
        $jsonRpc = '{"jsonrpc":"2.0","method":"test","id":1}';
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', ''] // Assume no origin or valid for now
        ]);
        $this->mockStream->method('__toString')->willReturn($jsonRpc);

        $transport = $this->createTransport();
        $messages = $transport->receive();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('test', $messages[0]->method);
        $this->assertEquals(1, $messages[0]->id);
    }

    public function testReceiveBatchPostRequest()
    {
        $jsonRpc = '[{"jsonrpc":"2.0","method":"test1","id":1},{"jsonrpc":"2.0","method":"test2","id":2}]';
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', '']
        ]);
        $this->mockStream->method('__toString')->willReturn($jsonRpc);

        $transport = $this->createTransport();
        $messages = $transport->receive();

        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);
        $this->assertEquals('test1', $messages[0]->method);
        $this->assertEquals('test2', $messages[1]->method);
    }

    public function testReceivePostRequestInvalidJson()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/JSON Parse Error/');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', '']
        ]);
        $this->mockStream->method('__toString')->willReturn('{"invalidjson');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceivePostRequestInvalidContentType()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Invalid Content-Type. Must be application/json.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'text/plain'], // Invalid
            ['Origin', '']
        ]);
        $this->mockStream->method('__toString')->willReturn('{}');

        $transport = $this->createTransport();
        $transport->receive();
    }

    public function testReceiveGetRequestReturnsNull()
    {
        $this->mockRequest->method('getMethod')->willReturn('GET');
        // No need to mock getHeaderLine for Content-Type as it shouldn't be checked for GET body
        $transport = $this->createTransport();
        $messages = $transport->receive();
        $this->assertNull($messages);
    }

    // --- Tests for header extraction ---

    public function testGetClientSessionId()
    {
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Mcp-Session-Id', 'session-123'],
            ['Last-Event-ID', ''],
            ['Origin', '']
        ]);
        $transport = $this->createTransport();
        $this->assertEquals('session-123', $transport->getClientSessionId());
    }

    public function testGetLastEventId()
    {
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Mcp-Session-Id', ''],
            ['Last-Event-ID', 'event-456'],
            ['Origin', '']
        ]);
        $transport = $this->createTransport();
        $this->assertEquals('event-456', $transport->getLastEventId());
    }

    public function testGetClientSessionIdNotPresent()
    {
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Mcp-Session-Id', ''], // Empty or not present
            ['Last-Event-ID', ''],
            ['Origin', '']
        ]);
        $transport = $this->createTransport();
        $this->assertNull($transport->getClientSessionId());
    }

    // --- Tests for Origin Validation in receive() ---

    public function testReceiveFailsWithInvalidOrigin()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Origin not allowed.');

        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', 'http://malicious.com'] // This origin is not in allowed list
        ]);
        $this->mockStream->method('__toString')->willReturn('{}');

        // Allowed origins list is empty, so any Origin header makes it fail
        $transport = $this->createTransport([]);
        $transport->receive();
    }

    public function testReceiveSucceedsWithValidOrigin()
    {
        $jsonRpc = '{"jsonrpc":"2.0","method":"test","id":1}';
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', 'http://good.com']
        ]);
        $this->mockStream->method('__toString')->willReturn($jsonRpc);

        $transport = $this->createTransport(['http://good.com']);
        $messages = $transport->receive();
        $this->assertCount(1, $messages);
    }

    public function testReceiveSucceedsWithNoOriginHeaderAndEmptyAllowedList()
    {
        $jsonRpc = '{"jsonrpc":"2.0","method":"test","id":1}';
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Origin', ''] // No Origin header sent
        ]);
        $this->mockStream->method('__toString')->willReturn($jsonRpc);

        $transport = $this->createTransport([]); // Empty allowed list
        $messages = $transport->receive();
        $this->assertCount(1, $messages);
    }

    // --- Tests for send() method - JSON responses ---

    public function testSendJsonResponseSingleMessage()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        // Assume client does not strongly prefer text/event-stream for a single response
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'application/json'],
            ['Origin', ''] // Assuming valid origin or no origin header
        ]);

        $transport = $this->createTransport();
        $rpcResponse = JsonRpcMessage::result(['foo' => 'bar'], '1');
        $transport->send($rpcResponse);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $content = json_decode((string) $response->getBody(), true);
        $this->assertEquals(['jsonrpc' => '2.0', 'id' => '1', 'result' => ['foo' => 'bar']], $content);
    }

    public function testSendJsonResponseBatchMessage()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'application/json'],
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        $rpcResponses = [
            JsonRpcMessage::result(['foo' => 'bar'], '1'),
            JsonRpcMessage::error(-32600, 'Invalid Request', '2')
        ];
        $transport->send($rpcResponses);

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $content = json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $content);
        $this->assertEquals('1', $content[0]['id']);
        $this->assertEquals(-32600, $content[1]['error']['code']);
    }

    public function testSendJsonResponseWithServerSessionId()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'application/json'],
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        $transport->setServerSessionId('server-session-789'); // Server sets this

        $rpcResponse = JsonRpcMessage::result(['status' => 'ok'], '3');
        $transport->send($rpcResponse);

        $response = $transport->getResponse();
        $this->assertEquals('server-session-789', $response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testSendReturns202ForNotificationOnlyBatch()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'application/json, text/event-stream'], // Client might accept SSE
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        $notifications = [
            new JsonRpcMessage('notify/event1', ['data' => 'value1']),
            new JsonRpcMessage('notify/event2', ['data' => 'value2'])
        ];
        $transport->send($notifications);

        $response = $transport->getResponse();
        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }

    public function testSendReturns202ForResponseOnlyBatch()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST');
         $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'application/json, text/event-stream'],
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        // This scenario (sending only responses from client to server) is unusual for MCP send()
        // send() is for server -> client.
        // But the isNotificationOrResponseOnlyBatch() check exists.
        // Let's assume these are JsonRpcMessage objects that are marked as responses.
        $responses = [
            JsonRpcMessage::result(['data' => 'value1'], 'id1'),
            JsonRpcMessage::error(100, 'Error msg', 'id2')
        ];
        // Manually mark them as not requests for the test's purpose
        $reflectionClass = new \ReflectionClass(JsonRpcMessage::class);
        $isRequestProp = $reflectionClass->getProperty('isRequest');
        $isRequestProp->setAccessible(true);
        foreach ($responses as $res) {
            $isRequestProp->setValue($res, false);
        }

        $transport->send($responses);

        $response = $transport->getResponse();
        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }


    // --- Tests for send() method - SSE responses ---

    public function testStartSseStreamForGetRequest()
    {
        $this->mockRequest->method('getMethod')->willReturn('GET');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'text/event-stream'],
            ['Origin', ''] // Assuming valid origin
        ]);

        $transport = $this->createTransport();

        // For SSE, send() might send initial headers and events are echoed.
        // We need to capture echoed output.
        ob_start();
        // Sending an empty array or a specific message to trigger SSE mode
        $transport->send([]); // Or a single message if that's how it's designed to start
        $output = ob_get_contents();
        ob_end_clean();

        $response = $transport->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertEquals('no', $response->getHeaderLine('X-Accel-Buffering'));

        // Check for initial SSE comments or data if any are sent by default
        // For example, if HttpTransport sends an opening comment or an initial empty event:
        // $this->assertStringContainsString(": stream open\n\n", $output);
        // Or, if it sends the initial [] as an event:
        if (!empty(trim($output))) {
             $this->assertMatchesRegularExpression('/^id: .+\ndata: \[\]\n\n/m', $output);
        } else {
            // If no output is echoed immediately for an empty send on SSE stream start, that's also fine.
            // The main check is the headers on $response.
            $this->assertTrue(true, "No immediate output, which is acceptable for SSE stream start.");
        }
    }

    public function testStartSseStreamWithServerSessionId()
    {
        $this->mockRequest->method('getMethod')->willReturn('GET');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'text/event-stream'],
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        $transport->setServerSessionId('sse-session-123');

        ob_start();
        $transport->send([]); // Trigger SSE stream
        ob_end_clean();

        $response = $transport->getResponse();
        $this->assertEquals('sse-session-123', $response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testSendSseEventAfterStreamStarted()
    {
        $this->mockRequest->method('getMethod')->willReturn('POST'); // Or GET
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'text/event-stream'],
            ['Origin', '']
        ]);

        $transport = $this->createTransport();
        $transport->setServerSessionId('s1'); // For event IDs

        // Start stream (first send might just set headers and return initial response)
        ob_start();
        $initialMessage = JsonRpcMessage::result(['status' => 'stream ready'], 'init');
        $transport->send($initialMessage); // This will be an SSE event due to Accept header

        $rpcEvent = JsonRpcMessage::notification('stream/update', ['value' => 42]);
        $transport->send($rpcEvent); // This should be a subsequent SSE event

        $output = ob_get_contents();
        ob_end_clean();

        // The first event (initialMessage)
        $expectedEvent1Regex = '/^id: s1-1\ndata: \{"jsonrpc":"2.0","id":"init","result":\{"status":"stream ready"\}\}\n\n/m';
        // The second event (rpcEvent)
        $expectedEvent2Regex = '/^id: s1-2\ndata: \{"jsonrpc":"2.0","method":"stream\/update","params":\{"value":42\}\}\n\n/m';

        $this->assertMatchesRegularExpression($expectedEvent1Regex, $output);
        $this->assertMatchesRegularExpression($expectedEvent2Regex, $output);
    }

    public function testSendSseEventWithMultiLineData()
    {
        // This test depends on how JsonRpcMessage::toJson actually formats newlines
        // and how prepareSseData handles them. The current prepareSseData replaces "
" with "
data: ".
        $this->mockRequest->method('getMethod')->willReturn('POST');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'text/event-stream'],
            ['Origin', '']
        ]);
        $transport = $this->createTransport();
        $transport->setServerSessionId('sMulti');

        ob_start();
        // Assume result contains a string with actual newlines
        $multiLineData = ["line1\nline2", "another field"];
        $rpcMessage = JsonRpcMessage::result($multiLineData, 'multiline-id');
        $transport->send($rpcMessage);
        $output = ob_get_contents();
        ob_end_clean();

        $jsonPayload = json_encode($rpcMessage->toJsonRpcData()['result']); // Get the result part as it was
        $sseFormattedPayload = str_replace("\n", "\ndata: ", $jsonPayload);

        // Check that the output contains the correctly formatted multi-line data
        $expectedRegex = '/^id: sMulti-1\ndata: ' . preg_quote($sseFormattedPayload, '/') . '\n\n/m';
        $this->assertMatchesRegularExpression($expectedRegex, $output);
    }

    public function testSendFailsForInvalidOriginOnGetSse()
    {
        $this->mockRequest->method('getMethod')->willReturn('GET');
        $this->mockRequest->method('getHeaderLine')->willReturnMap([
            ['Accept', 'text/event-stream'],
            ['Origin', 'http://bad-origin.com'] // Invalid origin
        ]);

        // Allowed origins list is empty in createTransport default
        $transport = $this->createTransport([]);

        ob_start();
        $transport->send([]); // Attempt to start SSE stream
        $output = ob_get_contents();
        ob_end_clean();

        $response = $transport->getResponse();
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Origin not allowed', (string) $response->getBody());
        $this->assertEmpty($output, "No SSE events should be echoed on origin validation failure.");
    }

    // TODO: Consider adding more nuanced SSE tests if HttpTransport evolves:
    // - Behavior when headers are already sent by external means.
    // - Specific timing of flushes if that becomes configurable/critical.
    // - Interaction with `isClosed()` during an active SSE stream.
}
