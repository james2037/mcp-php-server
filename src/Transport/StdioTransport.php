<?php

namespace MCP\Server\Transport;

use MCP\Server\Message\JsonRpcMessage;
use MCP\Server\Exception\TransportException;

class StdioTransport extends AbstractTransport
{
    private $stdin;
    private $stdout;
    private $stderr;

    public function __construct()
    {
        $this->stdin = $this->getInputStream();
        $this->stdout = $this->getOutputStream();
        $this->stderr = $this->getErrorStream();
    }

    public function receive(): ?JsonRpcMessage
    {
        try {
            $line = fgets($this->stdin);
            if ($line === false) {
                return null;
            }

            $line = trim($line);
            if ($line === '') {
                return null;
            }

            return $this->decodeMessage($line);
        } catch (\Exception $e) {
            $this->log("Error receiving message: " . $e->getMessage());
            return null;
        }
    }

    public function send(JsonRpcMessage $message): void
    {
        $json = $this->encodeMessage($message);

        if (strpos($json, "\n") !== false) {
            throw new TransportException("Message contains newlines");
        }

        $written = fwrite($this->stdout, $json . "\n");  // Now using the stored stream
        if ($written === false || $written !== strlen($json) + 1) {
            throw new TransportException("Failed to write complete message");
        }

        fflush($this->stdout);
    }

    public function log(string $message): void
    {
        fwrite($this->stderr, $message . "\n");  // Now using the stored stream
        fflush($this->stderr);
    }

    protected function getInputStream()
    {
        return STDIN;
    }

    protected function getOutputStream()
    {
        return STDOUT;
    }

    protected function getErrorStream()
    {
        return STDERR;
    }

    public function isClosed(): bool
    {
        return feof($this->stdin);
    }
}
