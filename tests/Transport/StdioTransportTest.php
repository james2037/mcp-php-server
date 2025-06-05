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

    public function testReceiveInvalidJsonThrowsException(): void // Renamed and Updated
    {
        $this->transport->writeToInput('{"invalidjson');
        $this->expectException(\RuntimeException::class);
        // StdioTransport::receive catches JsonException and re-throws RuntimeException with code PARSE_ERROR
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->transport->receive();
    }

    public function testReceiveEmptyLineReturnsNull(): void // Renamed and Updated
    {
        $this->transport->writeToInput(''); // Empty line, but still a line
        $receivedMessages = $this->transport->receive();
        $this->assertNull($receivedMessages); // As per StdioTransport logic for empty line
    }

    public function testReceiveStreamClosedReturnsEmptyArray(): void // Revised logic for EOF
    {
        // Write one line
        $this->transport->writeToInput('{"jsonrpc":"2.0","method":"ping","id":1}');
        // Consume that one line
        $firstResult = $this->transport->receive();
        $this->assertNotNull($firstResult);
        if ($firstResult !== null) { // Check to satisfy static analyzer
             $this->assertCount(1, $firstResult);
        }

        // Now try to receive again, fgets should return false as no more data
        $messages = $this->transport->receive();
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testReceiveInvalidJsonStructureThrowsException(): void
    {
        $this->transport->writeToInput('"just a string"'); // A JSON scalar
        $this->expectException(\RuntimeException::class);
        // This specific message comes from the generic catch (\Exception $e) block
        // that re-throws the initially thrown "Invalid JSON-RPC message structure." exception.
        $this->expectExceptionMessage('Error parsing JSON-RPC message: Invalid JSON-RPC message structure.');
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST); // Code from the generic catch block
        $this->transport->receive();
    }

    public function testReceiveCatchesExceptionFromJsonRpcMessage(): void
    {
        // This JSON is an object but is missing the 'method' field,
        // which JsonRpcMessage::fromJson() will throw an error for.
        $this->transport->writeToInput('{"jsonrpc": "2.0", "id": 1}');
        $this->expectException(\RuntimeException::class);
        // This message comes from the generic catch (\Exception $e) block
        // re-throwing the exception from JsonRpcMessage::fromJson().
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST); // Code from the generic catch block
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
        // Test errorLog as it's unconditional. debugLog is conditional.
        $this->transport->errorLog('Test error log message');
        $errorLogOutput = $this->transport->readFromError();
        // StdioTransport::errorLog prepends [ERROR] and a space, then adds a newline.
        $this->assertEquals("[ERROR] Test error log message\n", $errorLogOutput);
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

    public function testReceiveAnotherValidBatchRequest(): void
    {
        $batch = [
            ['jsonrpc' => '2.0', 'method' => 'sum', 'params' => [1, 2, 3], 'id' => 'req1'],
            ['jsonrpc' => '2.0', 'method' => 'notify_hello', 'params' => ['name' => 'World']],
            ['jsonrpc' => '2.0', 'method' => 'get_data', 'id' => 'req2']
        ];
        $jsonBatch = json_encode($batch);
        $this->assertIsString($jsonBatch);
        $this->transport->writeToInput($jsonBatch);

        $messages = $this->transport->receive();
        $this->assertIsArray($messages);
        $this->assertCount(3, $messages);

        // Message 1: Request with params and id
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('sum', $messages[0]->method);
        $this->assertEquals([1, 2, 3], $messages[0]->params);
        $this->assertEquals('req1', $messages[0]->id);
        $this->assertTrue($messages[0]->isRequest());

        // Message 2: Notification with params
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[1]);
        $this->assertEquals('notify_hello', $messages[1]->method);
        $this->assertEquals(['name' => 'World'], $messages[1]->params);
        $this->assertNull($messages[1]->id);
        $this->assertFalse($messages[1]->isRequest());

        // Message 3: Request without params, with id
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[2]);
        $this->assertEquals('get_data', $messages[2]->method);
        $this->assertNull($messages[2]->params);
        $this->assertEquals('req2', $messages[2]->id);
        $this->assertTrue($messages[2]->isRequest());
    }
}
