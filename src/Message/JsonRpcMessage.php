<?php

namespace MCP\Server\Message;

class JsonRpcMessage
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public string $jsonrpc = '2.0';
    public ?string $id;
    public string $method;
    public ?array $params;
    public ?array $result = null;
    public ?array $error = null;

    public function __construct(string $method, ?array $params = null, ?string $id = null)
    {
        $this->method = $method;
        $this->params = $params;
        $this->id = $id;
    }

    public function isRequest(): bool
    {
        return $this->id !== null;
    }

    public function toJson(): string
    {
        $data = ['jsonrpc' => $this->jsonrpc];

        if ($this->error !== null) {
            $data['error'] = $this->error;
            $data['id'] = $this->id;
        } elseif ($this->result !== null) {
            $data['result'] = $this->result;
            $data['id'] = $this->id;
        } else {
            $data['method'] = $this->method;
            if ($this->params !== null) {
                $data['params'] = $this->params;
            }
            if ($this->id !== null) {
                $data['id'] = $this->id;
            }
        }

        return json_encode($data);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON', self::PARSE_ERROR);
        }

        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new \RuntimeException('Invalid JSON-RPC version', self::INVALID_REQUEST);
        }

        // Handle response messages
        if (isset($data['result']) || isset($data['error'])) {
            if (!isset($data['id'])) {
                throw new \RuntimeException('Response must include ID', self::INVALID_REQUEST);
            }
            $msg = new self('', null, $data['id']);
            if (isset($data['result'])) {
                $msg->result = $data['result'];
            } else {
                $msg->error = $data['error'];
            }
            return $msg;
        }

        // Handle request/notification messages
        if (!isset($data['method'])) {
            throw new \RuntimeException('Missing method', self::INVALID_REQUEST);
        }

        return new self(
            $data['method'],
            $data['params'] ?? null,
            $data['id'] ?? null
        );
    }

    public static function result(array $result, string $id): self
    {
        $msg = new self('', null, $id);
        $msg->result = $result;
        return $msg;
    }

    public static function error(int $code, string $message, ?string $id, $data = null): self
    {
        $msg = new self('', null, $id);
        $msg->error = [
            'code' => $code,
            'message' => $message
        ];
        if ($data !== null) {
            $msg->error['data'] = $data;
        }
        return $msg;
    }
}
