<?php

namespace MCP\Server\Tests\Message;

use MCP\Server\Message\JsonRpcMessage;
use PHPUnit\Framework\TestCase;
use LogicException;

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

    public function testFromJsonWithNonArrayData(): void
    {
        $json = '"not an object"'; // Decodes to a string, not an array/object
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::PARSE_ERROR);
        $this->expectExceptionMessage('Invalid JSON: Decoded data is not an array.');
        JsonRpcMessage::fromJson($json);
    }

    public function testFromJsonMissingJsonRpcVersion(): void
    {
        $json = '{"method": "test", "id": "1"}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Invalid JSON-RPC version');
        JsonRpcMessage::fromJson($json);
    }

    public function testFromJsonIncorrectJsonRpcVersion(): void
    {
        $json = '{"jsonrpc": "1.0", "method": "test", "id": "1"}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Invalid JSON-RPC version');
        JsonRpcMessage::fromJson($json);
    }

    public function testFromJsonResponseMissingResultAndError(): void
    {
        $json = '{"jsonrpc": "2.0", "id": "1"}'; // Lacks result and error
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        // This falls through to request parsing, then fails on missing method
        $this->expectExceptionMessage('Missing or invalid method name in JSON-RPC request');
        JsonRpcMessage::fromJson($json);
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

    public function testToJsonHandlesJsonEncodeFailure(): void
    {
        // Deliberately include invalid UTF-8 characters
        $message = new JsonRpcMessage('test.method', ['data' => "\xB1\x31"], '123'); // Invalid UTF-8 for ±1

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INTERNAL_ERROR);
        $this->expectExceptionMessage('Failed to encode JSON-RPC message.');

        $message->toJson();
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
        $this->assertArrayNotHasKey('data', $decoded['error']); // Ensure data is not present when not provided
    }

    public function testCreateErrorResponseWithData(): void
    {
        $errorData = ['debug_info' => 'Additional details about the error.'];
        $message = JsonRpcMessage::error(
            JsonRpcMessage::INTERNAL_ERROR,
            'Internal error',
            'err-987',
            $errorData
        );

        $json = $message->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('err-987', $decoded['id']);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(JsonRpcMessage::INTERNAL_ERROR, $decoded['error']['code']);
        $this->assertEquals('Internal error', $decoded['error']['message']);
        $this->assertArrayHasKey('data', $decoded['error']);
        $this->assertEquals($errorData, $decoded['error']['data']);
    }

    // Tests for jsonSerialize behavior (invoked by json_encode)
    public function testJsonSerializeResultWithNullIdThrowsException(): void
    {
        $message = new JsonRpcMessage('placeholderMethod'); // Method needed for constructor
        $message->result = ['data' => 'success'];
        $message->id = null; // Explicitly set ID to null for a result message

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Result message must have an ID.');
        json_encode($message);
    }

    public function testJsonSerializeRequestMissingMethodThrowsException(): void
    {
        $message = new JsonRpcMessage(''); // Start with empty method
        $message->method = ''; // Ensure method is empty
        $message->id = '123'; // Make it a request
        $message->result = null;
        $message->error = null;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Request message must have a method.');
        json_encode($message);
    }

    public function testJsonSerializeNotificationMissingMethodThrowsException(): void
    {
        $message = new JsonRpcMessage(''); // Start with empty method
        $message->method = ''; // Ensure method is empty
        $message->id = null; // Make it a notification
        $message->result = null;
        $message->error = null;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Request message must have a method.');
        json_encode($message);
    }

    public function testJsonSerializeNotificationWithNullIdInOutput(): void
    {
        // Constructor sets id to null if not provided or explicitly null.
        $message = new JsonRpcMessage('notify.method', ['param' => 'value'], null);

        $json = json_encode($message);
        $this->assertIsString($json); // Ensure json_encode didn't fail
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('id', $decoded, 'ID should not be present for notifications.');
        $this->assertEquals('notify.method', $decoded['method']);
        $this->assertEquals(['param' => 'value'], $decoded['params']);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
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
        // @phpstan-ignore-next-line Test expects this to fail due to mixed types.
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
