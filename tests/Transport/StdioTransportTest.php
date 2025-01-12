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

    protected function getInputStream()
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
}

class StdioTransportTest extends TestCase
{
    private TestableStdioTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new TestableStdioTransport();
    }

    public function testCanSendAndReceiveMessage(): void
    {
        // Write a test message to input
        $requestMsg = [
            'jsonrpc' => '2.0',
            'method' => 'test.method',
            'params' => ['hello' => 'world'],
            'id' => '123'
        ];
        $this->transport->writeToInput(json_encode($requestMsg));

        // Read the message using transport
        $received = $this->transport->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $received);
        $this->assertEquals('test.method', $received->method);
        $this->assertEquals(['hello' => 'world'], $received->params);
        $this->assertEquals('123', $received->id);

        // Send a response
        $response = JsonRpcMessage::result(['result' => 'success'], '123');
        $this->transport->send($response);

        // Check output contains correct response
        $output = $this->transport->readFromOutput();
        $decoded = json_decode(trim($output), true);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('123', $decoded['id']);
        $this->assertEquals(['result' => 'success'], $decoded['result']);
    }

    public function testHandlesInvalidJson(): void
    {
        $this->transport->writeToInput('invalid json');
        $received = $this->transport->receive();
        $this->assertNull($received);

        $error = $this->transport->readFromError();
        $this->assertStringContainsString('Error parsing message', $error);
    }

    public function testHandlesEmptyInput(): void
    {
        $this->transport->writeToInput('');
        $received = $this->transport->receive();
        $this->assertNull($received);
    }

    public function testLogging(): void
    {
        $this->transport->log('Test log message');
        $error = $this->transport->readFromError();
        $this->assertEquals("Test log message\n", $error);
    }
}
