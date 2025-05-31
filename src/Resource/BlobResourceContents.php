<?php

declare(strict_types=1);

namespace MCP\Server\Resource;

class BlobResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public readonly string $blob,
        string $mimeType
    ) {
        parent::__construct($uri, $mimeType);
    }

    public function toArray(): array
    {
        $data = ['uri' => $this->uri, 'blob' => $this->blob];
        // $this->mimeType is guaranteed by constructor, but check doesn't hurt for consistency
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        return $data;
    }
}
