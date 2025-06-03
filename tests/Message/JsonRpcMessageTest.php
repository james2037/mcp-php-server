<?php

namespace MCP\Server\Tests\Message;

use MCP\Server\Message\JsonRpcMessage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MCP\Server\Message\JsonRpcMessage
 */
class JsonRpcMessageTest extends TestCase
{
    public function testCreateRequest(): void
    {
        $message = new JsonRpcMessage('test.method', ['param' => 'value'], '123');

        $this->assertEquals('test.method', $message->method);
        $this->assertEquals(['param' => 'value'], $message->params);
        $this->assertEquals('123', $message->id);
        $this->assertTrue($message->isRequest());
    }

    public function testCreateNotification(): void
    {
        $message = new JsonRpcMessage('test.method', ['param' => 'value']);

        $this->assertEquals('test.method', $message->method);
        $this->assertEquals(['param' => 'value'], $message->params);
        $this->assertNull($message->id);
        $this->assertFalse($message->isRequest());
    }

    public function testFromJson(): void
    {
        $json = '{"jsonrpc":"2.0","method":"test.method","params":{"param":"value"},"id":"123"}';
        $message = JsonRpcMessage::fromJson($json);

        $this->assertEquals('test.method', $message->method);
        $this->assertEquals(['param' => 'value'], $message->params);
        $this->assertEquals('123', $message->id);
    }

    public function testToJson(): void
    {
        $message = new JsonRpcMessage('test.method', ['param' => 'value'], '123');
        $json = $message->toJson();

        $decoded = json_decode($json, true);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('test.method', $decoded['method']);
        $this->assertEquals(['param' => 'value'], $decoded['params']);
        $this->assertEquals('123', $decoded['id']);
    }

    public function testCreateErrorResponse(): void
    {
        $message = JsonRpcMessage::error(
            JsonRpcMessage::METHOD_NOT_FOUND,
            'Method not found',
            '123'
        );

        $json = $message->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('123', $decoded['id']);
        $this->assertEquals(JsonRpcMessage::METHOD_NOT_FOUND, $decoded['error']['code']);
        $this->assertEquals('Method not found', $decoded['error']['message']);
    }

    // New test methods for fromJsonArray
    public function testFromJsonArrayValidBatchRequest(): void
    {
        $json = '[{"jsonrpc": "2.0", "method": "foo", "params": {"bar": 1}, "id": 1}, {"jsonrpc": "2.0", "method": "baz", "id": 2}]';
        $messages = JsonRpcMessage::fromJsonArray($json);

        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);

        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('foo', $messages[0]->method);
        $this->assertEquals(['bar' => 1], $messages[0]->params);
        $this->assertEquals(1, $messages[0]->id);

        $this->assertInstanceOf(JsonRpcMessage::class, $messages[1]);
        $this->assertEquals('baz', $messages[1]->method);
        $this->assertNull($messages[1]->params); // Should be null if params field is missing
        $this->assertEquals(2, $messages[1]->id);
    }

    public function testFromJsonArrayEmptyBatchRequest(): void
    {
        $json = '[]';
        $messages = JsonRpcMessage::fromJsonArray($json);
        $this->assertIsArray($messages);
        $this->assertCount(0, $messages);
    }

    public function testFromJsonArrayInvalidJson(): void
    {
        $json = '[{"method": "foo", "id": 1},'; // Malformed JSON
        $this->expectException(\JsonException::class); // json_decode with JSON_THROW_ON_ERROR throws JsonException
        // The specific code might be JsonRpcMessage::PARSE_ERROR or a general JSON parse error code
        // depending on PHP's json_decode behavior with JSON_THROW_ON_ERROR.
        // JsonRpcMessage::fromJsonArray itself throws with self::PARSE_ERROR if $data is not an array,
        // but json_decode with JSON_THROW_ON_ERROR throws JsonException.
        // Let's check for JsonRpcMessage::PARSE_ERROR as per the method's own throws for non-array $data.
        // However, for truly malformed JSON, JsonException is more likely.
        // For this test, a JsonException is expected from json_decode.
        // RuntimeException is a good parent.
        JsonRpcMessage::fromJsonArray($json);
    }

    public function testFromJsonArrayNotAnArray(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "foo", "id": 1}'; // Single object, not an array
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessage('Invalid JSON: Expected an array of messages.');
        JsonRpcMessage::fromJsonArray($json);
    }

    public function testFromJsonArrayContainsInvalidMessage(): void
    {
        // Second message is missing 'method'
        $json = '[{"jsonrpc": "2.0", "method": "notify", "params": {}}, {"jsonrpc": "2.0", "id": 1}]';
        $this->expectException(\RuntimeException::class);
        // This will be caught by fromJson when processing the individual message
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        JsonRpcMessage::fromJsonArray($json);
    }

    // New test methods for toJsonArray
    public function testToJsonArrayValidBatchResponse(): void
    {
        $message1 = JsonRpcMessage::result(['foo' => 'bar'], '1');
        $message2 = JsonRpcMessage::error(-32600, 'Invalid Request', '2');
        $messages = [$message1, $message2];

        $json = JsonRpcMessage::toJsonArray($messages);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);

        $this->assertEquals('2.0', $decoded[0]['jsonrpc']);
        $this->assertEquals('1', $decoded[0]['id']);
        $this->assertEquals(['foo' => 'bar'], $decoded[0]['result']);

        $this->assertEquals('2.0', $decoded[1]['jsonrpc']);
        $this->assertEquals('2', $decoded[1]['id']);
        $this->assertEquals(-32600, $decoded[1]['error']['code']);
        $this->assertEquals('Invalid Request', $decoded[1]['error']['message']);
    }

    public function testToJsonArrayEmptyBatchResponse(): void
    {
        $json = JsonRpcMessage::toJsonArray([]);
        $this->assertEquals('[]', $json);
    }

    public function testToJsonArrayWithNonMessageThrowsException(): void
    {
        $messages = [JsonRpcMessage::result([], '1'), new \stdClass()];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All items in the array must be JsonRpcMessage objects.');
        JsonRpcMessage::toJsonArray($messages);
    }

    // New test methods for toResponseArray
    public function testToResponseArrayForResult(): void
    {
        $message = JsonRpcMessage::result(['foo' => 'bar'], '1');
        $array = $message->toResponseArray();

        $this->assertEquals('2.0', $array['jsonrpc']);
        $this->assertEquals('1', $array['id']);
        $this->assertEquals(['foo' => 'bar'], $array['result']);
        $this->assertArrayNotHasKey('error', $array);
    }

    public function testToResponseArrayForError(): void
    {
        $message = JsonRpcMessage::error(-32600, 'Invalid Request', '1');
        $array = $message->toResponseArray();

        $this->assertEquals('2.0', $array['jsonrpc']);
        $this->assertEquals('1', $array['id']);
        $this->assertEquals(-32600, $array['error']['code']);
        $this->assertEquals('Invalid Request', $array['error']['message']);
        $this->assertArrayNotHasKey('result', $array);
    }

    public function testToResponseArrayForRequestThrowsException(): void
    {
        $message = new JsonRpcMessage('test', [], '1'); // This is a request
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Message is not a response or error, cannot convert to response array.');
        $message->toResponseArray();
    }

    public function testToResponseArrayForNotificationThrowsException(): void
    {
        $message = new JsonRpcMessage('test', []); // This is a notification
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Message is not a response or error, cannot convert to response array.');
        $message->toResponseArray();
    }
}
