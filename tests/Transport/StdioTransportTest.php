<?php

namespace MCP\Server\Tests\Transport;

use PHPUnit\Framework\TestCase;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Tests\Transport\ProtectedAccessorStdioTransport;

class StdioTransportTest extends TestCase
{
    private TestableStdioTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new TestableStdioTransport();
    }

    // testCanSendAndReceiveMessage is removed.

    public function testReceiveSingleRequest(): void
    {
        $request = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $jsonRequest = json_encode($request);
        $this->assertIsString($jsonRequest);
        $this->transport->writeToInput($jsonRequest);

        $messages = $this->transport->receive();
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('test', $messages[0]->method);
        $this->assertEquals(1, $messages[0]->id);
    }

    public function testReceiveBatchRequest(): void
    {
        $batch = [
            ['jsonrpc' => '2.0', 'method' => 'notify1', 'params' => ['p1' => 'v1']],
            ['jsonrpc' => '2.0', 'method' => 'req1', 'id' => 'abc']
        ];
        $jsonBatch = json_encode($batch);
        $this->assertIsString($jsonBatch);
        $this->transport->writeToInput($jsonBatch);

        $messages = $this->transport->receive();
        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('notify1', $messages[0]->method);
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[1]);
        $this->assertEquals('req1', $messages[1]->method);
        $this->assertEquals('abc', $messages[1]->id);
    }

    public function testReceiveEmptyJsonArray(): void
    {
        $this->transport->writeToInput('[]');
        $messages = $this->transport->receive();
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testReceiveInvalidJsonThrowsTransportException(): void
    {
        $this->transport->writeToInput('{"invalidjson');
        $this->expectException(\MCP\Server\Exception\TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessageMatches('/Failed to decode JSON/');
        $this->transport->receive();
    }

    public function testReceiveEmptyLineReturnsNull(): void
    {
        // TestableStdioTransport->writeToInput adds a newline.
        // fgets will read this newline. parseMessages will trim it.
        // trim("\n") is empty, so parseMessages returns null.
        $this->transport->writeToInput('');
        $receivedMessages = $this->transport->receive();
        $this->assertNull($receivedMessages);
    }

    public function testReceiveStreamClosedReturnsFalse(): void
    {
        // Write one line
        $this->transport->writeToInput('{"jsonrpc":"2.0","method":"ping","id":1}');
        // Consume that one line
        $firstResult = $this->transport->receive();
        $this->assertNotNull($firstResult, "First receive() call should return a message or null, not false EOF yet.");
        if (is_array($firstResult)) { // Ensure it's an array before counting
             $this->assertCount(1, $firstResult);
        } else {
            // If it's not an array here, it might be null (empty line), which is unexpected for this specific test input.
            // Or it could be false if the stream was already at EOF, also unexpected here.
            // Fail if it's not an array, as this test expects a valid message first.
            $this->fail("Expected an array of messages from the first receive() call, got " . gettype($firstResult));
        }

        // Now try to receive again, fgets should return false as no more data
        $messages = $this->transport->receive();
        $this->assertFalse($messages);
    }

    public function testReceiveInvalidJsonStructureThrowsTransportException(): void
    {
        $this->transport->writeToInput('"just a string"'); // A JSON scalar
        $this->expectException(\MCP\Server\Exception\TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Decoded JSON is not an array or object/');
        $this->transport->receive();
    }

    public function testReceiveInvalidRpcStructureThrowsTransportException(): void
    {
        // This JSON is an object but is missing the 'method' field,
        // which JsonRpcMessage::fromJson() will throw an error for (via parseMessages).
        $this->transport->writeToInput('{"jsonrpc": "2.0", "id": 1}');
        $this->expectException(\MCP\Server\Exception\TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        // Message will be something like "Error parsing single message: Missing or invalid method name..."
        $this->expectExceptionMessageMatches('/Error parsing single message: Missing or invalid method name in JSON-RPC request/');
        $this->transport->receive();
    }

    public function testSendSingleMessage(): void
    {
        $message = JsonRpcMessage::result(['foo' => 'bar'], '1');
        $this->transport->send($message);
        $output = $this->transport->readFromOutput();
        // StdioTransport send adds a newline
        $this->assertJsonStringEqualsJsonString('{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}}', trim($output));
    }

    public function testSendBatchMessage(): void
    {
        $batchResponse = [
            JsonRpcMessage::result(['foo' => 'bar'], '1'),
            JsonRpcMessage::error(-32600, 'Invalid Request', '2')
        ];
        $this->transport->send($batchResponse);
        $output = $this->transport->readFromOutput();
        // StdioTransport send adds a newline
        $expectedJson = '[{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}},{"jsonrpc":"2.0","id":"2","error":{"code":-32600,"message":"Invalid Request"}}]';
        $this->assertJsonStringEqualsJsonString($expectedJson, trim($output));
    }

    public function testLogging(): void // Updated assertion
    {
        $this->transport->log('Test log message');
        $errorLogOutput = $this->transport->readFromError();
        // StdioTransport::log uses fwrite($this->stderr, $message . "\n");
        // It does not add static::class prefix when errorStream is available (which it is in TestableStdioTransport)
        $this->assertEquals("Test log message\n", $errorLogOutput);
    }

    public function testIsClosed(): void
    {
        $this->assertFalse($this->transport->isClosed(), "Initially, transport should not be closed");

        // Write a single message to trigger reading from input
        $this->transport->writeToInput('{"jsonrpc":"2.0","method":"test","id":1}');

        // First receive() consumes the message
        $messages = $this->transport->receive();
        $this->assertNotNull($messages, "Should receive one message");

        // Second receive() should encounter EOF on the input stream
        $this->transport->receive();

        // After EOF is reached on the input stream, isClosed() should return true
        $this->assertTrue($this->transport->isClosed(), "Transport should be closed after EOF");
    }

    public function testIsStreamOpen(): void
    {
        $this->assertFalse($this->transport->isStreamOpen());
    }

    public function testOriginalGetStreamMethodsCoverage(): void
    {
        // For this test, we want to ensure STDIN, STDOUT, STDERR are defined
        // or at least don't cause errors when StdioTransport tries to use them.
        // PHPUnit might run in environments where these are not standard streams.
        // However, the goal is to hit the lines in StdioTransport.
        // If STDIN/OUT/ERR are not actual streams, they might be null or closed resources.
        // The primary assertion is that the methods are callable and return a resource,
        // which means the lines in StdioTransport were executed.

        if (!defined('STDIN')) {
            define('STDIN', fopen('php://memory', 'r'));
        }
        if (!defined('STDOUT')) {
            define('STDOUT', fopen('php://memory', 'w'));
        }
        if (!defined('STDERR')) {
            define('STDERR', fopen('php://memory', 'w'));
        }

        $accessorTransport = new ProtectedAccessorStdioTransport();

        $inputStream = $accessorTransport->callParentGetInputStream();
        $this->assertIsResource($inputStream, "STDIN should be a resource via parent getInputStream");

        $outputStream = $accessorTransport->callParentGetOutputStream();
        $this->assertIsResource($outputStream, "STDOUT should be a resource via parent getOutputStream");

        $errorStream = $accessorTransport->callParentGetErrorStream();
        $this->assertIsResource($errorStream, "STDERR should be a resource via parent getErrorStream");
    }
}
