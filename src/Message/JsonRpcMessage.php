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

    /**
     * Returns the array representation of the message, for responses or errors.
     *
     * @return array The array representation of the message.
     * @throws \LogicException If the message is not a response or error.
     */
    public function toResponseArray(): array
    {
        if (isset($this->error)) {
            return ['jsonrpc' => $this->jsonrpc, 'id' => $this->id, 'error' => $this->error];
        }

        if (isset($this->result)) {
            return ['jsonrpc' => $this->jsonrpc, 'id' => $this->id, 'result' => $this->result];
        }

        throw new \LogicException('Message is not a response or error, cannot convert to response array.');
    }

    /**
     * Parses a JSON string representing an array of JSON-RPC messages.
     *
     * @param  string $json The JSON string to parse.
     * @return array An array of JsonRpcMessage objects.
     * @throws \RuntimeException If JSON decoding fails or the result is not an array.
     */
    public static function fromJsonArray(string $json): array
    {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON: Expected an array of messages.', self::PARSE_ERROR);
        }

        $messages = [];
        foreach ($data as $item) {
            // Re-encode each item to use the existing fromJson method
            // This is less efficient but reuses existing parsing logic
            $itemJson = json_encode($item, JSON_THROW_ON_ERROR);
            $messages[] = self::fromJson($itemJson);
        }

        return $messages;
    }

    /**
     * Serializes an array of JsonRpcMessage objects into a JSON string.
     *
     * @param  array $messages An array of JsonRpcMessage objects.
     * @return string The JSON string representation of the messages.
     */
    public static function toJsonArray(array $messages): string
    {
        $dataArray = [];
        foreach ($messages as $message) {
            if (!$message instanceof self) {
                throw new \InvalidArgumentException('All items in the array must be JsonRpcMessage objects.');
            }
            // Each message (request, response, or notification) is converted to its array form
            // json_decode($message->toJson(), true) correctly gets the array structure for any message type
            $decodedMessage = json_decode($message->toJson(), true);
            if ($decodedMessage === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode individual message to array: ' . json_last_error_msg());
            }
            $dataArray[] = $decodedMessage;
        }

        return json_encode($dataArray, JSON_THROW_ON_ERROR);
    }
}
