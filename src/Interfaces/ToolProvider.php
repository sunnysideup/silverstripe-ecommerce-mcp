<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Interfaces;

/**
 * Anything that exposes one or more MCP tools.
 */
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;

interface ToolProvider
{
    /**
     * Tool definitions as they appear in tools/list. Input schemas are
     * JSON Schema 2020-12 with an object root.
     *
     * @return list<array{name: string, title?: string, description: string, inputSchema: array}>
     */
    public function definitions(): array;

    /**
     * Run a tool. Return any JSON-serialisable structure; the registry wraps it.
     *
     * @throws JsonRpcException on invalid arguments
     */
    public function execute(string $name, array $arguments): mixed;
}
