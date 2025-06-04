<?php

namespace MCP\Server\Tests\Transport;

use MCP\Server\Exception\TransportException;
use MCP\Server\Message\JsonRpcMessage;
use PHPUnit\Framework\TestCase;

class AbstractTransportTest extends TestCase
{
    private TestableAbstractTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new TestableAbstractTransport();
    }

    public function testParseMessagesReturnsNullForEmptyRawData(): void
    {
        $this->assertNull($this->transport->callParseMessages(""));
        $this->assertNull($this->transport->callParseMessages("   "));
        $this->assertNull($this->transport->callParseMessages("\t\n "));
    }

    public function testParseMessagesThrowsTransportExceptionForMalformedJson(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessageMatches('/Failed to decode JSON/');
        $this->transport->callParseMessages('{"jsonrpc": "2.0", "method": "foo", "params": [');
    }

    public function testParseMessagesThrowsTransportExceptionForJsonNumber(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Decoded JSON is not an array or object/');
        $this->transport->callParseMessages('123');
    }

    public function testParseMessagesThrowsTransportExceptionForJsonString(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Decoded JSON is not an array or object/');
        $this->transport->callParseMessages('"this is a string"');
    }

    public function testParseMessagesThrowsTransportExceptionForJsonNull(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Decoded JSON is not an array or object/');
        $this->transport->callParseMessages('null');
    }

    public function testParseMessagesThrowsTransportExceptionForOversizedMessage(): void
    {
        $maxSize = TestableAbstractTransport::getExposedMaxMessageSize();
        // Create a string that is slightly larger than the max size
        // A simple request: {"jsonrpc":"2.0","method":"m","id":1} (36 chars)
        // We need to pad it.
        $baseRequest = '{"jsonrpc":"2.0","method":"m","id":1'; // Remove closing }
        $paddingSize = $maxSize - strlen($baseRequest) + 1;
        $paddedRequest = $baseRequest . ',"padding":"' . str_repeat('a', $paddingSize) . '"}';

        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Raw data exceeds size limit/');
        $this->transport->callParseMessages($paddedRequest);
    }

    public function testParseMessagesParsesValidSingleRequest(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "subtract", "params": [42, 23], "id": 1}';
        $messages = $this->transport->callParseMessages($json);
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('2.0', $messages[0]->jsonrpc);
        $this->assertEquals('subtract', $messages[0]->method);
        $this->assertEquals([42, 23], $messages[0]->params);
        $this->assertEquals('1', $messages[0]->id);
    }

    public function testParseMessagesParsesValidBatchRequest(): void
    {
        $json = '[' .
            '{"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},' .
            '{"jsonrpc": "2.0", "method": "notify_hello", "params": [7]},' .
            '{"jsonrpc": "2.0", "method": "subtract", "params": [42,23], "id": "2"}' .
        ']';
        $messages = $this->transport->callParseMessages($json);
        $this->assertIsArray($messages);
        $this->assertCount(3, $messages);

        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('sum', $messages[0]->method);
        $this->assertEquals('1', $messages[0]->id);

        $this->assertInstanceOf(JsonRpcMessage::class, $messages[1]);
        $this->assertEquals('notify_hello', $messages[1]->method);
        $this->assertNull($messages[1]->id);

        $this->assertInstanceOf(JsonRpcMessage::class, $messages[2]);
        $this->assertEquals('subtract', $messages[2]->method);
        $this->assertEquals('2', $messages[2]->id);
    }

    public function testParseMessagesParsesEmptyBatchRequest(): void
    {
        $json = '[]';
        $messages = $this->transport->callParseMessages($json);
        $this->assertIsArray($messages);
        $this->assertCount(0, $messages);
    }

    public function testParseMessagesThrowsForInvalidItemInBatchNotAnObject(): void
    {
        $json = '[{"jsonrpc": "2.0", "method": "foo", "id": "1"}, 123]'; // 123 is not an object
        $this->expectException(TransportException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Invalid item in batch at index 1: not an object\/array/');
        $this->transport->callParseMessages($json);
    }

    public function testParseMessagesThrowsForInvalidItemInBatchMalformedRpc(): void
    {
        // Item is an object, but not a valid RPC message (missing method)
        $json = '[{"jsonrpc": "2.0", "id": "1"}]';
        $this->expectException(TransportException::class);
        // This code comes from JsonRpcMessage::fromJson via the re-thrown TransportException
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Error parsing message in batch at index 0: Missing or invalid method name/');
        $this->transport->callParseMessages($json);
    }

    public function testParseMessagesThrowsForFailedReEncodingInBatch(): void
    {
        // This test is tricky because json_encode failing for an array element
        // from json_decode($rawData, true) is very unlikely unless there are
        // specific unserializable objects or recursion, which json_decode($rawData, true) shouldn't produce.
        // We can simulate by trying to create an invalid UTF-8 sequence if the system is sensitive.
        // For now, this is a placeholder, as it's hard to reliably trigger json_encode failure
        // from a structure that json_decode produced.
        // A more direct way would be to mock json_encode if the class was designed for it.
        // Let's assume valid re-encoding for now and rely on other tests for coverage.
        $this->markTestSkipped('Difficult to reliably trigger json_encode failure for an array item from json_decode in this context.');
    }

    public function testParseMessagesThrowsForSingleInvalidRpcStructure(): void
    {
        // Item is an object, but not a valid RPC message (missing method)
        $json = '{"jsonrpc": "2.0", "id": "1"}';
        $this->expectException(TransportException::class);
        // This code comes from JsonRpcMessage::fromJson via the re-thrown TransportException
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/Error parsing single message: Missing or invalid method name/');
        $this->transport->callParseMessages($json);
    }
}
