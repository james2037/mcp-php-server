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

    public function testFromJsonWithResultField(): void
    {
        $json = '{"jsonrpc":"2.0","result":{"data":"success"},"id":"res-123"}';
        $message = JsonRpcMessage::fromJson($json);

        $this->assertSame('res-123', $message->id);
        $this->assertSame(['data' => 'success'], $message->result);
        $this->assertSame('', $message->method);
        $this->assertNull($message->params);
        $this->assertNull($message->error);
    }

    public function testFromJsonWithErrorField(): void
    {
        $json = '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":"err-456"}';
        $message = JsonRpcMessage::fromJson($json);

        $this->assertSame('err-456', $message->id);
        $this->assertSame(['code' => -32600, 'message' => 'Invalid Request'], $message->error);
        $this->assertSame('', $message->method);
        $this->assertNull($message->params);
        $this->assertNull($message->result);
    }

    public function testFromJsonResponseMissingId(): void
    {
        // This JSON represents a result response but is missing the 'id' field.
        $json = '{"jsonrpc": "2.0", "result": {"foo": "bar"}}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Response must include ID (even if null for some error cases as per spec, this implementation expects it)');
        JsonRpcMessage::fromJson($json);
    }

    public function testFromJsonErrorResponseMissingId(): void
    {
        // This JSON represents an error response but is missing the 'id' field.
        $json = '{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid Request"}}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Response must include ID (even if null for some error cases as per spec, this implementation expects it)');
        JsonRpcMessage::fromJson($json);
    }

    public function testFromJsonErrorFieldNotAnArray(): void
    {
        // This JSON represents an error response, but the 'error' field is a string, not an object/array.
        $json = '{"jsonrpc": "2.0", "error": "This should be an error object", "id": "err-789"}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        $this->expectExceptionMessage('Invalid error object in JSON-RPC response');
        JsonRpcMessage::fromJson($json);
    }

    public function testJsonSerializeRequestWithId(): void
    {
        $message = new JsonRpcMessage('test.request', ['foo' => 'bar'], 'req-789');
        // Ensure it's treated as a request by jsonSerialize, not result/error
        $message->result = null;
        $message->error = null;

        $serialized = $message->jsonSerialize();

        $this->assertArrayHasKey('id', $serialized);
        $this->assertEquals('req-789', $serialized['id']);
        $this->assertEquals('test.request', $serialized['method']);
        $this->assertEquals(['foo' => 'bar'], $serialized['params']);
        $this->assertEquals('2.0', $serialized['jsonrpc']);
    }

    public function testFromJsonArrayMixedRequestNotificationBatch(): void
    {
        $json = '[
            {"jsonrpc": "2.0", "method": "do_work", "params": {"task": "important"}, "id": "req-001"},
            {"jsonrpc": "2.0", "method": "log_message", "params": {"level": "info", "message": "Processing complete"}}
        ]';
        $messages = JsonRpcMessage::fromJsonArray($json);

        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);

        // First message (request)
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[0]);
        $this->assertEquals('do_work', $messages[0]->method);
        $this->assertEquals(['task' => 'important'], $messages[0]->params);
        $this->assertEquals('req-001', $messages[0]->id);
        $this->assertTrue($messages[0]->isRequest());

        // Second message (notification)
        $this->assertInstanceOf(JsonRpcMessage::class, $messages[1]);
        $this->assertEquals('log_message', $messages[1]->method);
        $this->assertEquals(['level' => 'info', 'message' => 'Processing complete'], $messages[1]->params);
        $this->assertNull($messages[1]->id);
        $this->assertFalse($messages[1]->isRequest());
    }

    // --- Tests for fromJsonObject ---

    public function testFromJsonObjectValidRequest(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'method' => 'subtract',
            'params' => (object)['subtrahend' => 23, 'minuend' => 42],
            'id' => 'req-001'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals('subtract', $message->method);
        $this->assertEquals(['subtrahend' => 23, 'minuend' => 42], $message->params);
        $this->assertEquals('req-001', $message->id);
        $this->assertTrue($message->isRequest());
        $this->assertEquals('2.0', $message->jsonrpc);
    }

    public function testFromJsonObjectValidRequestWithArrayParams(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'method' => 'sum',
            'params' => [1, 2, 3], // Params as array
            'id' => 'req-002'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals('sum', $message->method);
        $this->assertEquals([1, 2, 3], $message->params);
        $this->assertEquals('req-002', $message->id);
    }

    public function testFromJsonObjectValidNotification(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'method' => 'log',
            'params' => (object)['message' => 'hello']
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals('log', $message->method);
        $this->assertEquals(['message' => 'hello'], $message->params);
        $this->assertNull($message->id);
        $this->assertFalse($message->isRequest());
    }

    public function testFromJsonObjectValidNotificationMissingParams(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'method' => 'ping'
            // No 'params' property
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals('ping', $message->method);
        $this->assertNull($message->params);
        $this->assertNull($message->id);
    }


    public function testFromJsonObjectValidSuccessResponse(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'result' => (object)['status' => 'ok', 'data' => [1,2]],
            'id' => 'resp-001'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals(['status' => 'ok', 'data' => [1,2]], $message->result);
        $this->assertEquals('resp-001', $message->id);
        $this->assertNull($message->error);
        // For responses, method/params are not primary, constructor sets method to ''
        $this->assertEquals('', $message->method);
    }

    public function testFromJsonObjectValidSuccessResponseNullResult(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'result' => null,
            'id' => 'resp-002'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertNull($message->result);
        $this->assertEquals('resp-002', $message->id);
    }


    public function testFromJsonObjectValidErrorResponse(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'error' => (object)['code' => -32000, 'message' => 'Server error'],
            'id' => 'err-001'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals(['code' => -32000, 'message' => 'Server error'], $message->error);
        $this->assertEquals('err-001', $message->id);
        $this->assertNull($message->result);
    }

    public function testFromJsonObjectValidErrorResponseWithData(): void
    {
        $errorData = (object)['details' => 'trace info'];
        $data = (object)[
            'jsonrpc' => '2.0',
            'error' => (object)['code' => -32000, 'message' => 'Server error', 'data' => $errorData],
            'id' => 'err-002'
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals(['code' => -32000, 'message' => 'Server error', 'data' => ['details' => 'trace info']], $message->error);
        $this->assertEquals('err-002', $message->id);
    }

    public function testFromJsonObjectErrorResponseNullId(): void
    {
        $data = (object)[
            'jsonrpc' => '2.0',
            'error' => (object)['code' => -32700, 'message' => 'Parse error'],
            'id' => null // ID can be null for some errors (e.g., parse error before ID is known)
        ];
        $message = JsonRpcMessage::fromJsonObject($data);
        $this->assertEquals(['code' => -32700, 'message' => 'Parse error'], $message->error);
        $this->assertNull($message->id);
    }

    public function testFromJsonObjectInvalidMissingJsonRpc(): void
    {
        $data = (object)['method' => 'foo', 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC version');
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectInvalidVersion(): void
    {
        $data = (object)['jsonrpc' => '1.0', 'method' => 'foo', 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC version');
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectRequestMissingMethod(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing, invalid, or empty method name in JSON-RPC request');
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectRequestMethodNotString(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'method' => 123, 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing, invalid, or empty method name in JSON-RPC request');
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectRequestMethodEmpty(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'method' => '', 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing, invalid, or empty method name in JSON-RPC request');
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectInvalidParamsTypeScalar(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'method' => 'foo', 'params' => 123, 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid params: must be object or array if present.');
        $this->expectExceptionCode(JsonRpcMessage::INVALID_PARAMS);
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectInvalidIdTypeObject(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'method' => 'foo', 'id' => (object)['id_val' => 1]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid ID: must be string, number, or null.');
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectErrorNotObject(): void
    {
        $data = (object)['jsonrpc' => '2.0', 'error' => 'i am not an object', 'id' => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid error object in JSON-RPC response (must be an object)');
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectResponseResultScalar(): void
    {
        // Based on current fromJsonObject logic, scalar results are not allowed.
        $data = (object)['jsonrpc' => '2.0', 'result' => 'a scalar result', 'id' => 'res-scalar'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response result must be a structured type (object/array) or null.');
        $this->expectExceptionCode(JsonRpcMessage::INVALID_REQUEST);
        JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectResponseMissingId(): void
    {
        // Current fromJsonObject implementation defaults to null ID if 'id' property is missing for a response.
        // This differs from the original fromJson which was stricter.
        // Let's test this implemented behavior.
        $data = (object)['jsonrpc' => '2.0', 'result' => (object)['status' => 'ok']];
        $message = JsonRpcMessage::fromJsonObject($data); // Should not throw, ID will be null
        $this->assertNull($message->id);
        $this->assertEquals(['status' => 'ok'], $message->result);

        // If strict ID presence for response was desired (like original fromJson):
        // $this->expectException(\RuntimeException::class);
        // $this->expectExceptionMessage('Response must include ID');
        // JsonRpcMessage::fromJsonObject($data);
    }

    public function testFromJsonObjectResponseIdInvalidType(): void
    {
        // Current fromJsonObject implementation defaults to null ID if 'id' property is not string/numeric.
        $data = (object)['jsonrpc' => '2.0', 'result' => (object)['status' => 'ok'], 'id' => (object)['complex' => 'id']];
        $message = JsonRpcMessage::fromJsonObject($data); // Should not throw, ID will be null
        $this->assertNull($message->id);

        // If strict ID typing for response (string/numeric only, no auto-null for bad type) was desired:
        // $this->expectException(\RuntimeException::class);
        // $this->expectExceptionMessage('Invalid ID: must be string, number, or null.'); // Or specific for response
        // JsonRpcMessage::fromJsonObject($data);
    }
}
