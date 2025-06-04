# TODO List - MCP PHP SDK Feature Implementation

This document tracks features from the Model Context Protocol (MCP) specification that need to be implemented or verified in this PHP SDK.

## Protocol Core & JSON-RPC

*   **[ ] Batch Requests/Responses:**
    *   Verify/implement full support for `JSONRPCBatchRequest` and `JSONRPCBatchResponse` as per the specification.
    *   Ensure `StdioTransport` can handle batched messages.
    *   Ensure `HttpTransport` can handle batched messages in POST bodies (as per 2025-03-26 spec).
*   **[ ] Cancellation (`notifications/cancelled`):**
    *   Implement server-side handling for `CancelledNotification` to gracefully stop operations.
    *   Ensure transports correctly forward this notification.
*   **[ ] Progress Notifications (`notifications/progress`):**
    *   Implement a mechanism for capabilities/tools to send progress updates.
    *   Ensure `Server.php` and transports can dispatch `ProgressNotification`.

## Capabilities

### General
*   **[ ] Client/Server Capabilities in `initialize`:**
    *   Verify and implement flags for dynamic capability advertisements (e.g., `roots.listChanged`, `prompts.listChanged`, `resources.subscribe`, etc.) in `InitializeResult`.

### Resources Capability
*   **[ ] Resource Templates (`resources/templates/list`):**
    *   Implement `ListResourceTemplatesRequest` and `ListResourceTemplatesResult`.
    *   Add `ResourceTemplate` class and allow registration in `ResourcesCapability`.
*   **[ ] Resource Subscriptions:**
    *   `resources/subscribe` (`SubscribeRequest`): Implement request handling.
    *   `resources/unsubscribe` (`UnsubscribeRequest`): Implement request handling.
    *   `notifications/resources/updated` (`ResourceUpdatedNotification`): Mechanism for resources to trigger this notification.
    *   `notifications/resources/list_changed` (`ResourceListChangedNotification`): Mechanism to notify clients about changes to the list of available resources.
*   **[ ] Pagination for `resources/list`:**
    *   Implement `PaginatedRequest` (`cursor` parameter) for `ListResourcesRequest`.
    *   Implement `PaginatedResult` (`nextCursor` field) for `ListResourcesResult`.
*   **[ ] Content Types:**
    *   Verify/add support for `BlobResourceContents` beyond `TextResourceContents` in `ReadResourceResult`.

### Tools Capability
*   **[ ] Tool Annotations:**
    *   Implement `ToolAnnotations` (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) in the `Tool` definition and include them in `ListToolsResult`.
*   **[ ] Pagination for `tools/list`:**
    *   Implement `PaginatedRequest` (`cursor` parameter) for `ListToolsRequest`.
    *   Implement `PaginatedResult` (`nextCursor` field) for `ListToolsResult`.
*   **[ ] Additional Content Types in `CallToolResult`:**
    *   Ensure `CallToolResult` can return `ImageContent`, `AudioContent`, and `EmbeddedResource` in addition to `TextContent`. The base `Tool` class's `createTextContent` etc. methods are a good start, but need to ensure the full flow.

### Prompts Capability
*   **[ ] `prompts/list` (`ListPromptsRequest`):**
    *   Implement request handling.
    *   Define `Prompt` structure (name, description, arguments).
    *   Return `ListPromptsResult`.
*   **[ ] `prompts/get` (`GetPromptRequest`):**
    *   Implement request handling.
    *   Handle prompt templating with `arguments`.
    *   Return `GetPromptResult` with `PromptMessage` (including support for `TextContent`, `ImageContent`, `AudioContent`, `EmbeddedResource`).
*   **[ ] `notifications/prompts/list_changed` (`PromptListChangedNotification`):**
    *   Mechanism to notify clients about changes to available prompts.
*   **[ ] Pagination for `prompts/list`:**
    *   Implement `PaginatedRequest` (`cursor` parameter).
    *   Implement `PaginatedResult` (`nextCursor` field).

### Logging Capability
*   **[ ] `logging/setLevel` (`SetLevelRequest`):**
    *   Implement request handling to allow clients to set the desired log level.
*   **[ ] `notifications/message` (`LoggingMessageNotification`):**
    *   Mechanism for the server/capabilities to send log messages to the client.

### Sampling Capability
*   **[ ] `sampling/createMessage` (`CreateMessageRequest`):**
    *   Implement request handling to forward sampling requests to the client. This is a server *requesting* the client to do something.
    *   The SDK itself won't perform sampling but needs to be able to send the request if a server component initiates it. (This might be more relevant for client SDKs, but the server should be able to send the request type).

### Autocomplete Capability
*   **[ ] `completion/complete` (`CompleteRequest`):**
    *   Implement request handling.
    *   Provide a mechanism for tools/resources to offer completion suggestions for their arguments.
    *   Return `CompleteResult`.

### Roots Capability
*   **[ ] `roots/list` (`ListRootsRequest`):**
    *   Implement request handling for servers to ask clients for root URIs. (Similar to sampling, this is a server request to the client).
*   **[ ] `notifications/roots/list_changed` (`RootsListChangedNotification`):**
    *   Handle this notification *from* the client. (This is a client notification to the server).

## Transports

### StdioTransport
*   **[ ] Verify Batch Message Handling:** Explicitly test sending and receiving batched JSON-RPC messages.

### HttpTransport (Streamable HTTP - 2025-03-26 Spec)
*   **[ ] SSE Streaming for POST responses:**
    *   If a client POST contains JSON-RPC requests, the server must be able to respond with `Content-Type: text/event-stream` and stream multiple messages (responses, notifications, further requests) related to the initial POST.
*   **[ ] GET endpoint for Server-Sent Events:**
    *   Allow clients to establish an SSE connection via GET to receive unsolicited messages from the server.
*   **[ ] Multiple Connections:**
    *   Ensure the server can manage multiple concurrent SSE streams from one or more clients (consider implications for session state).
*   **[ ] Resumability and Redelivery (`Last-Event-ID`):**
    *   Support clients attempting to resume disconnected SSE streams using the `Last-Event-ID` header.
*   **[ ] Session Management (`Mcp-Session-Id` header):**
    *   Implement generation and handling of `Mcp-Session-Id` for stateful sessions as per the spec.
    *   Support `DELETE` requests for session termination.
*   **[ ] Backwards Compatibility with older HTTP+SSE transport (2024-11-05):**
    *   Consider if the SDK should offer built-in support or guidance for supporting the older HTTP+SSE transport alongside the new Streamable HTTP.

## General SDK Enhancements
*   **[ ] Comprehensive Examples:** Add more examples for each implemented capability and feature.
*   **[ ] Detailed Documentation:** Improve inline documentation and generate API docs.

## Improve Discovery Error Handling

- Consider logging a warning or providing a configurable option to throw an exception when the `discover` method in `Registry.php` encounters files that are skipped (e.g., non-class files, abstract classes, resources/tools missing required attributes). Currently, these are skipped silently.
