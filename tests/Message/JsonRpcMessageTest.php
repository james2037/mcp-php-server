<?php

namespace MCP\Server\Tests\Message;

use MCP\Server\Message\JsonRpcMessage;
use PHPUnit\Framework\TestCase;

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
}
