# MCP PHP SDK Examples

This directory contains example implementations of MCP servers using the PHP SDK.

## STDIO Server (`stdio_server.php`)

This example demonstrates an MCP server that communicates over standard input/output. It includes an `EchoTool` and a `GreetingResource`.

### Running the Server

To run the STDIO server, execute the following command in your terminal:

```bash
php examples/stdio_server.php
```
The server will print `STDIO Server listening...` to STDERR and then wait for JSON-RPC messages on STDIN.

### Sending Requests

You can send JSON-RPC messages to the server by typing them into the terminal or by piping them from a file or command. Each JSON-RPC request should be on a new line.

**Example Input (provide this to the server's STDIN):**

```json
{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26"},"id":"init1"}
{"jsonrpc":"2.0","method":"tools/call","params":{"name":"echo","arguments":{"message":"Hello STDIO Example"}},"id":"echo1"}
{"jsonrpc":"2.0","method":"shutdown","id":"shutdown1"}
```

**Expected Output (from the server's STDOUT):**

When you provide the input above, the server will produce the following output. Note that each JSON-RPC response is sent as a batch response containing a single item (i.e., wrapped in a `[]`).

```json
[{"jsonrpc":"2.0","result":{"protocolVersion":"2025-03-26","capabilities":{"tools":{"listChanged":false},"resources":{"listChanged":false},"logging":{},"completions":{}},"serverInfo":{"name":"MySimpleServer (STDIO)","version":"1.0.0"},"instructions":"This server implements the Model Context Protocol (MCP) and provides the following capabilities:"},"id":"init1"}]
[{"jsonrpc":"2.0","result":{"content":[{"type":"text","text":"Echo: Hello STDIO Example"}],"isError":false},"id":"echo1"}]
[{"jsonrpc":"2.0","result":[],"id":"shutdown1"}]
```
*(The `...` in the initialize response's capabilities field indicates that the actual capabilities list might be longer depending on the exact server setup, but `tools` and `resources` will be present as shown from the example code.)*


## HTTP Server (`http_server.php`)

This example demonstrates an MCP server that communicates over HTTP. It uses the same `EchoTool` and `GreetingResource` as the STDIO example.

### Running the Server

To run the HTTP server for development, use PHP's built-in web server:

```bash
php -S localhost:8000 examples/http_server.php
```
The server will start listening for HTTP requests on `localhost:8000`.

### Sending Requests

You can send MCP requests to the server using any HTTP client, such as `curl`.

**Example `curl` Request:**

This command sends a `tools/call` request to the `echo` tool. Since HTTP is stateless, for operations other than `initialize` that require an initialized state, you typically send `initialize` and the subsequent request(s) as a batch. If you only send `tools/call` without prior initialization in the same request, the server would correctly respond with a "Server not initialized" error.

The following `curl` command sends a batch request containing `initialize` and `tools/call`:

```bash
curl -X POST -H "Content-Type: application/json" \
     -d '[{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26"},"id":"init_http_1"},{"jsonrpc":"2.0","method":"tools/call","params":{"name":"echo","arguments":{"message":"Hello HTTP Example"}},"id":"echo_http_1"}]' \
     http://localhost:8000/
```

**Expected JSON Output:**

The server will respond with a JSON array containing responses for both requests in the batch:

```json
[{"jsonrpc":"2.0","result":{"protocolVersion":"2025-03-26","capabilities":{"tools":{"listChanged":false},"resources":{"listChanged":false},"logging":{},"completions":{}},"serverInfo":{"name":"MySimpleServer (HTTP)","version":"1.0.0"},"instructions":"This server implements the Model Context Protocol (MCP) and provides the following capabilities:"},"id":"init_http_1"},{"jsonrpc":"2.0","result":{"content":[{"type":"text","text":"Echo: Hello HTTP Example"}],"isError":false},"id":"echo_http_1"}]
```
*(Similar to the STDIO example, the `...` in the initialize response's capabilities indicates a potentially longer list.)*

For production use, you would deploy `http_server.php` behind a web server like Nginx or Apache, as described in the main `README.md` in the root of this repository.
