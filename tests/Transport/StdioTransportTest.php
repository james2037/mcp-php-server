<?php

namespace MCP\Server\Tests\Transport;

use PHPUnit\Framework\TestCase;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Message\JsonRpcMessage;

class TestableStdioTransport extends StdioTransport
{
    private $_input;
    private $_output;
    private $_error;

    public function __construct()
    {
        // Create memory streams for testing
        $this->_input = fopen('php://memory', 'r+');
        $this->_output = fopen('php://memory', 'r+');
        $this->_error = fopen('php://memory', 'r+');

        // Call parent constructor after initializing our streams
        parent::__construct();
    }

    public function getInputStream() // Changed from protected
    {
        return $this->_input;
    }

    protected function getOutputStream()
    {
        return $this->_output;
    }

    protected function getErrorStream()
    {
        return $this->_error;
    }

    // Helper methods for testing
    public function writeToInput(string $data): void
    {
        // Append new data to the input stream
        // StdioTransport::receive() reads line by line, so ensure input stream pointer is at the end for writing.
        fseek($this->_input, 0, SEEK_END); // Move to the end before writing
        fwrite($this->_input, $data . "\n");
        // Reset position to start for reading by receive()
        fseek($this->_input, 0);
    }

    public function readFromOutput(): string
    {
        fseek($this->_output, 0);
        $content = stream_get_contents($this->_output);
        ftruncate($this->_output, 0);
        fseek($this->_output, 0);
        return $content;
    }

    public function readFromError(): string
    {
        fseek($this->_error, 0);
        $content = stream_get_contents($this->_error);
        ftruncate($this->_error, 0);
        fseek($this->_error, 0);
        return $content;
    }

    public function readMultipleJsonOutputs(): array
    {
        fseek($this->_output, 0);
        $content = stream_get_contents($this->_output);
        ftruncate($this->_output, 0);
        fseek($this->_output, 0);

        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", trim($content));
        $decodedOutputs = [];
        foreach ($lines as $line) {
            if (trim($line) === '') { continue;
            }
            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Failed to decode JSON line: " . $line . " - Error: " . json_last_error_msg());
            }
            $decodedOutputs[] = $decoded;
        }
        return $decodedOutputs;
    }
}

class StdioTransportTest extends TestCase
{
    private TestableStdioTransport $_transport;

    protected function setUp(): void
    {
        $this->_transport = new TestableStdioTransport();
    }

    // testCanSendAndReceiveMessage is removed.

    public function testReceiveSingleRequest(): void
    {
        $request = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $this->_transport->writeToInput(json_encode($request));

        $messages = $this->_transport->receive();
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
        $this->_transport->writeToInput(json_encode($batch));

        $messages = $this->_transport->receive();
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
        $this->_transport->writeToInput('[]');
        $messages = $this->_transport->receive();
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testReceiveInvalidJsonThrowsException(): void // Renamed and Updated
    {
        $this->_transport->writeToInput('{"invalidjson');
        $this->expectException(\RuntimeException::class);
        // StdioTransport::receive catches JsonException and re-throws RuntimeException with code PARSE_ERROR
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->_transport->receive();
    }

    public function testReceiveEmptyLineReturnsNull(): void // Renamed and Updated
    {
        $this->_transport->writeToInput(''); // Empty line, but still a line
        $receivedMessages = $this->_transport->receive();
        $this->assertNull($receivedMessages); // As per StdioTransport logic for empty line
    }

    public function testReceiveStreamClosedReturnsEmptyArray(): void // Revised logic for EOF
    {
        // Write one line
        $this->_transport->writeToInput('{"jsonrpc":"2.0","method":"ping","id":1}');
        // Consume that one line
        $firstResult = $this->_transport->receive();
        $this->assertNotNull($firstResult);
        if ($firstResult !== null) { // Check to satisfy static analyzer
             $this->assertCount(1, $firstResult);
        }

        // Now try to receive again, fgets should return false as no more data
        $messages = $this->_transport->receive();
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testSendSingleMessage(): void
    {
        $message = JsonRpcMessage::result(['foo' => 'bar'], '1');
        $this->_transport->send($message);
        $output = $this->_transport->readFromOutput();
        // StdioTransport send adds a newline
        $this->assertJsonStringEqualsJsonString('{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}}', trim($output));
    }

    public function testSendBatchMessage(): void
    {
        $batchResponse = [
            JsonRpcMessage::result(['foo' => 'bar'], '1'),
            JsonRpcMessage::error(-32600, 'Invalid Request', '2')
        ];
        $this->_transport->send($batchResponse);
        $output = $this->_transport->readFromOutput();
        // StdioTransport send adds a newline
        $expectedJson = '[{"jsonrpc":"2.0","id":"1","result":{"foo":"bar"}},{"jsonrpc":"2.0","id":"2","error":{"code":-32600,"message":"Invalid Request"}}]';
        $this->assertJsonStringEqualsJsonString($expectedJson, trim($output));
    }

    public function testLogging(): void // Updated assertion
    {
        $this->_transport->log('Test log message');
        $errorLogOutput = $this->_transport->readFromError();
        // StdioTransport::log uses fwrite($this->_stderr, $message . "\n");
        // It does not add static::class prefix when errorStream is available (which it is in TestableStdioTransport)
        $this->assertEquals("Test log message\n", $errorLogOutput);
    }
}
