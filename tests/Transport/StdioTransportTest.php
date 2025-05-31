<?php

namespace MCP\Server\Tests\Transport;

use PHPUnit\Framework\TestCase;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Message\JsonRpcMessage;

class TestableStdioTransport extends StdioTransport
{
    private $input;
    private $output;
    private $error;

    public function __construct()
    {
        // Create memory streams for testing
        $this->input = fopen('php://memory', 'r+');
        $this->output = fopen('php://memory', 'r+');
        $this->error = fopen('php://memory', 'r+');

        // Call parent constructor after initializing our streams
        parent::__construct();
    }

    public function getInputStream() // Changed from protected
    {
        return $this->input;
    }

    protected function getOutputStream()
    {
        return $this->output;
    }

    protected function getErrorStream()
    {
        return $this->error;
    }

    // Helper methods for testing
    public function writeToInput(string $data): void
    {
        // Clear the stream first
        ftruncate($this->input, 0);
        fseek($this->input, 0);
        // Write new data
        fwrite($this->input, $data . "\n");
        // Reset position to start for reading
        fseek($this->input, 0);
    }

    public function readFromOutput(): string
    {
        fseek($this->output, 0);
        $content = stream_get_contents($this->output);
        ftruncate($this->output, 0);
        fseek($this->output, 0);
        return $content;
    }

    public function readFromError(): string
    {
        fseek($this->error, 0);
        $content = stream_get_contents($this->error);
        ftruncate($this->error, 0);
        fseek($this->error, 0);
        return $content;
    }

    public function readMultipleJsonOutputs(): array
    {
        fseek($this->output, 0);
        $content = stream_get_contents($this->output);
        ftruncate($this->output, 0);
        fseek($this->output, 0);

        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", trim($content));
        $decodedOutputs = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
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
    private TestableStdioTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new TestableStdioTransport();
    }

    // testCanSendAndReceiveMessage is removed.

    public function testReceiveSingleRequest(): void
    {
        $request = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1];
        $this->transport->writeToInput(json_encode($request));

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
        $this->transport->writeToInput(json_encode($batch));

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
}
