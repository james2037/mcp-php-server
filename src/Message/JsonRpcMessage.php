<?php

namespace MCP\Server\Message;

/**
 * Represents a JSON-RPC 2.0 message, which can be a request, a response, or an error.
 * Implements JsonSerializable for direct use with json_encode.
 */
class JsonRpcMessage implements \JsonSerializable
{
    /** @var int JSON-RPC Parse error. */
    public const PARSE_ERROR = -32700;
    /** @var int JSON-RPC Invalid Request error. */
    public const INVALID_REQUEST = -32600;
    /** @var int JSON-RPC Method Not Found error. */
    public const METHOD_NOT_FOUND = -32601;
    /** @var int JSON-RPC Invalid Params error. */
    public const INVALID_PARAMS = -32602;
    /** @var int JSON-RPC Internal error. */
    public const INTERNAL_ERROR = -32603;

    /** @var string The JSON-RPC version. Defaults to "2.0". */
    public string $jsonrpc = '2.0';
    /** @var string|null An identifier established by the Client. Null for notifications. */
    public ?string $id;
    /** @var string The name of the method to be invoked. Present in requests. */
    public string $method;
    /** @var array<mixed>|null The structured value that holds the parameter values to be used during the invocation of the method. Present in requests. */
    public ?array $params;
    /** @var array<mixed>|null The value of this member is determined by the method invoked on the Server. Present in responses. */
    public ?array $result = null;
    /** @var array<string, mixed>|null An Error object if there was an error invoking the method. Present in error responses. */
    public ?array $error = null;

    /**
     * Constructs a new JsonRpcMessage, typically for a request or notification.
     *
     * @param string $method The method name.
     * @param array<mixed>|null $params The parameters, if any.
     * @param string|null $id The message ID. If null, it's a notification.
     */
    public function __construct(string $method, ?array $params = null, ?string $id = null)
    {
        $this->method = $method;
        $this->params = $params;
        $this->id = $id;
    }

    /**
     * Checks if the message is a request (i.e., it has an ID).
     *
     * @return bool True if the message has an ID, false otherwise.
     */
    public function isRequest(): bool
    {
        return $this->id !== null;
    }

    /**
     * Converts the message object to its JSON string representation.
     *
     * @return string The JSON string.
     */
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

        $encoded = json_encode($data);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode JSON-RPC message.', self::INTERNAL_ERROR);
        }
        return $encoded;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON: Decoded data is not an array.', self::PARSE_ERROR);
        }

        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new \RuntimeException('Invalid JSON-RPC version', self::INVALID_REQUEST);
        }

        // Handle response messages
        if (isset($data['result']) || isset($data['error'])) {
            // While spec says ID can be null for error responses to certain requests,
            // our construction requires an ID for responses.
            // This could be refined if strictly null IDs for errors are needed.
            if (!array_key_exists('id', $data)) {
                throw new \RuntimeException('Response must include ID (even if null for some error cases as per spec, this implementation expects it)', self::INVALID_REQUEST);
            }
            $msg = new self('', null, $data['id']); // Method and params are not relevant for responses.
            if (isset($data['result'])) {
                $msg->result = $data['result'];
            } else {
                if (!is_array($data['error'])) {
                    throw new \RuntimeException('Invalid error object in JSON-RPC response', self::INVALID_REQUEST);
                }
                $msg->error = $data['error'];
            }
            return $msg;
        }

        // Handle request/notification messages
        if (!isset($data['method']) || !is_string($data['method'])) {
            throw new \RuntimeException('Missing or invalid method name in JSON-RPC request', self::INVALID_REQUEST);
        }

        return new self(
            $data['method'],
            isset($data['params']) && is_array($data['params']) ? $data['params'] : null,
            isset($data['id']) && (is_string($data['id']) || is_numeric($data['id'])) ? (string)$data['id'] : null
        );
    }

    /**
     * Creates a JsonRpcMessage from a pre-decoded stdClass object.
     *
     * @param \stdClass $data The decoded JSON-RPC message object.
     * @return self The created JsonRpcMessage instance.
     * @throws \RuntimeException If the object structure is invalid.
     */
    public static function fromJsonObject(\stdClass $data): self
    {
        if (!property_exists($data, 'jsonrpc') || $data->jsonrpc !== '2.0') {
            throw new \RuntimeException('Invalid JSON-RPC version', self::INVALID_REQUEST);
        }

        // Handle response messages (result or error)
        if (property_exists($data, 'result') || property_exists($data, 'error')) {
            // According to JSON-RPC 2.0 spec, ID must be present for responses,
            // and should be the same as the ID of the request.
            // It can be null if the request ID was null (though unusual) or if there was an error
            // that prevented request ID parsing (e.g. Parse Error/Invalid Request).
            // Our constructor new self('', null, $id) expects $id to be string|null.
            $id = null;
            if (property_exists($data, 'id')) {
                // Ensure ID is string or null. Numeric IDs are cast to string.
                $id = (is_string($data->id) || is_numeric($data->id)) ? (string)$data->id : null;
            }
             // If ID is strictly required and not just potentially null:
             // if (!property_exists($data, 'id')) {
             //     throw new \RuntimeException('Response must include ID', self::INVALID_REQUEST);
             // }
             // $id = (string)$data->id; // Assuming ID here must not be null for a response.

            $msg = new self('', null, $id); // Method and params are not relevant for responses.

            if (property_exists($data, 'result')) {
                // Result can be any type, but we store it in an array field.
                // If it's a structured type (object/array), ensure it's an array.
                // If it's a scalar, it might be an issue if not wrapped in an array by convention.
                // For now, mirroring original behavior: whatever $data->result is, assign it.
                // The JsonRpcMessage::$result is type ?array. This implies result should be array or null.
                // Let's ensure it's compatible.
                if (is_object($data->result) || is_array($data->result)) {
                    $msg->result = (array)$data->result;
                } elseif ($data->result === null) {
                    $msg->result = null;
                } else {
                    // If result is a scalar, this was not explicitly handled before.
                    // Wrapping scalar in an array, or throwing error, are options.
                    // To be safe and expect a "structured value" usually:
                    throw new \RuntimeException('Response result must be a structured type (object/array) or null.', self::INVALID_REQUEST);
                }
            } else { // This means property_exists($data, 'error') is true
                if (!is_object($data->error)) { // Error member MUST be an object
                    throw new \RuntimeException('Invalid error object in JSON-RPC response (must be an object)', self::INVALID_REQUEST);
                }
                // Perform a shallow cast for the main error object
                $errorArray = (array)$data->error;

                // If the 'data' field within the error object exists and is an object, cast it to an array too
                if (isset($errorArray['data']) && is_object($errorArray['data'])) {
                    $errorArray['data'] = (array)$errorArray['data'];
                }
                $msg->error = $errorArray;
            }
            return $msg;
        }

        // Handle request or notification messages
        if (!property_exists($data, 'method') || !is_string($data->method) || empty($data->method)) {
            throw new \RuntimeException('Missing, invalid, or empty method name in JSON-RPC request', self::INVALID_REQUEST);
        }

        $params = null;
        if (property_exists($data, 'params')) {
            if (is_object($data->params) || is_array($data->params)) {
                // JSON-RPC params can be an array (positional) or object (named).
                // Our constructor expects ?array. So, convert object to array.
                $params = (array)$data->params;
            } else {
                // Params exist but are not a structured type (object/array)
                throw new \RuntimeException('Invalid params: must be object or array if present.', self::INVALID_PARAMS);
            }
        }

        $id = null;
        if (property_exists($data, 'id')) {
            if (is_string($data->id) || is_numeric($data->id)) {
                $id = (string)$data->id;
            } elseif ($data->id === null) { // Explicitly null is allowed (fixed phpcs warning)
                $id = null; // Explicitly null is allowed
            } else {
                // ID is present but of an invalid type (e.g., object, array)
                throw new \RuntimeException('Invalid ID: must be string, number, or null.', self::INVALID_REQUEST);
            }
        }

        return new self($data->method, $params, $id);
    }

    /**
     * Creates a JSON-RPC success response message.
     *
     * @param array<mixed> $result The result of the successful method execution.
     * @param string $id The ID of the original request.
     * @return self The created JsonRpcMessage object representing a success response.
     */
    public static function result(array $result, string $id): self
    {
        $msg = new self('', null, $id); // Method and params are not relevant for responses.
        $msg->result = $result;
        return $msg;
    }

    /**
     * Creates a JSON-RPC error response message.
     *
     * @param int $code The error code.
     * @param string $message A human-readable error message.
     * @param string|null $id The ID of the original request. Should be null if the error was parsing or an invalid request.
     * @param mixed|null $data Additional data associated with the error.
     * @return self The created JsonRpcMessage object representing an error response.
     */
    public static function error(int $code, string $message, ?string $id, $data = null): self
    {
        $msg = new self('', null, $id); // Method and params are not relevant for responses.
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
     * @return array<string, mixed> The array representation of the message.
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
     * @return self[] An array of JsonRpcMessage objects.
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
            // $item is already a PHP stdClass object from the initial json_decode.
            // Call the new fromJsonObject method directly.
            $messages[] = self::fromJsonObject($item);
        }

        return $messages;
    }

    /**
     * Serializes an array of JsonRpcMessage objects into a JSON string.
     *
     * @param  self[] $messages An array of JsonRpcMessage objects.
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
            // by leveraging jsonSerialize if the object is directly encodable,
            // or toJson then decode if we need to be sure.
            // Given we are adding jsonSerialize, this can be simplified.
            $dataArray[] = $message->jsonSerialize();
        }

        return json_encode($dataArray, JSON_THROW_ON_ERROR);
    }

    /**
     * Specifies the data which should be serialized to JSON.
     * Called by json_encode().
     *
     * @return array<string, mixed> The data to be serialized.
     * @throws \LogicException If the message state is inconsistent (e.g., result without ID).
     */
    public function jsonSerialize(): array
    {
        $data = ['jsonrpc' => $this->jsonrpc];

        // Order of checks matters: error, then result, then request/notification.
        if ($this->error !== null) {
            $data['error'] = $this->error;
            // JSON-RPC error objects require an ID, which can be null.
            $data['id'] = $this->id;
        } elseif ($this->result !== null) {
            $data['result'] = $this->result;
            // JSON-RPC result objects require an ID.
            if ($this->id === null) {
                // This case should ideally not happen for a valid result message.
                // Throwing an error or defaulting ID might be options.
                // For now, let's ensure ID is included.
                throw new \LogicException('Result message must have an ID.');
            }
            $data['id'] = $this->id;
        } else { // This is a request or notification
            if (!isset($this->method) || $this->method === '') {
                 // This indicates an improperly constructed message object if it's not an error/result.
                 throw new \LogicException('Request message must have a method.');
            }
            $data['method'] = $this->method;
            if ($this->params !== null) {
                $data['params'] = $this->params;
            }
            // ID is optional for notifications. If present, it's a request.
            if ($this->id !== null) {
                $data['id'] = $this->id;
            }
        }
        return $data;
    }
}
