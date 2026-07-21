<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Controllers;

use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Registry\ToolRegistry;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * MCP endpoint implementing the Streamable HTTP transport, 2026-07-28
 * revision (stateless core).
 *
 * Deliberately has no session, no handshake and no persistent state: every
 * POST is self-contained, which maps cleanly onto PHP-FPM's process-per-request
 * model. Any instance behind a round-robin load balancer can serve any request.
 *
 * Spec: https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http
 */
class McpController extends Controller
{
    /** Protocol revisions this server speaks. */
    private const SUPPORTED_VERSIONS = ['2026-07-28'];

    private const META_NS = 'io.modelcontextprotocol/';

    /** JSON-RPC error codes. */
    private const E_PARSE           = -32700;
    private const E_INVALID_REQUEST = -32600;
    private const E_METHOD_NOT_FOUND = -32601;
    private const E_INVALID_PARAMS  = -32602;
    private const E_INTERNAL        = -32603;
    private const E_HEADER_MISMATCH = -32020;

    private static array $allowed_actions = ['index'];

    private static array $allowed_origins = [];

    private static int $tools_list_ttl_ms = 3600000;

    public function index(HTTPRequest $request): HTTPResponse
    {
        // The GET stream endpoint and the DELETE session teardown were both
        // removed in this revision. Older clients may still try them.
        if (!$request->isPOST()) {
            return $this->plain(405, 'Method Not Allowed');
        }

        if (($origin = $request->getHeader('Origin')) && !$this->originAllowed($origin)) {
            return $this->plain(403, 'Forbidden');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->error(null, self::E_PARSE, 'Malformed JSON body', 400);
        }

        $id     = $body['id'] ?? null;
        $method = $body['method'] ?? null;
        $params = $body['params'] ?? [];

        if (!is_string($method)) {
            return $this->error($id, self::E_INVALID_REQUEST, 'Missing method', 400);
        }

        // A body with no id is a notification: acknowledge and do nothing.
        // This revision defines no client-to-server notifications over
        // Streamable HTTP, but clients on older revisions may still send them.
        if ($id === null) {
            return $this->plain(202, '');
        }

        try {
            $this->assertProtocolVersion($request, $params);
            $this->assertHeadersMatchBody($request, $method, $params);

            $result = $this->dispatch($method, is_array($params) ? $params : []);
        } catch (JsonRpcException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->getHttpStatus(), $e->getData());
        } catch (Throwable $e) {
            // Never leak stack traces to an agent.
            user_error($e->getMessage(), E_USER_WARNING);
            return $this->error($id, self::E_INTERNAL, 'Internal error', 500);
        }

        return $this->json(200, [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    // ---------------------------------------------------------------- routing

    private function dispatch(string $method, array $params): array
    {
        $registry = Injector::inst()->get(ToolRegistry::class);

        return match ($method) {
            'server/discover' => $this->discover(),
            'tools/list'      => [
                'tools'      => $registry->definitions(),
                // Advertised so clients and gateways can cache the tool
                // surface instead of re-fetching it before every call.
                'ttlMs'      => static::config()->get('tools_list_ttl_ms'),
                'cacheScope' => 'public',
            ],
            'tools/call' => $registry->call(
                (string) ($params['name'] ?? ''),
                (array) ($params['arguments'] ?? []),
            ),
            default => throw new JsonRpcException(
                self::E_METHOD_NOT_FOUND,
                sprintf('Unknown method "%s"', $method),
                404,
            ),
        };
    }

    /**
     * Capability discovery. Replaces the initialize/initialized handshake,
     * which no longer exists. Clients call this on demand rather than up front.
     */
    private function discover(): array
    {
        return [
            'protocolVersion' => self::SUPPORTED_VERSIONS[0],
            'serverInfo'      => [
                'name'    => 'catalogue',
                'title'   => 'Product Catalogue',
                'version' => '1.0.0',
            ],
            'capabilities'    => [
                // No listChanged: the catalogue tool surface is fixed, and
                // clients honour ttlMs instead.
                'tools' => new \stdClass(),
            ],
            'instructions' => implode(' ', [
                'Search this catalogue with search_products before answering',
                'questions about availability or price.',
                'Always cite the returned url when recommending a product.',
            ]),
            'ttlMs'      => static::config()->get('tools_list_ttl_ms'),
            'cacheScope' => 'public',
        ];
    }

    // ------------------------------------------------------------- validation

    private function assertProtocolVersion(HTTPRequest $request, mixed $params): void
    {
        $header = $request->getHeader('MCP-Protocol-Version');
        $meta   = is_array($params) ? ($params['_meta'][self::META_NS . 'protocolVersion'] ?? null) : null;

        if ($header === null) {
            throw new JsonRpcException(
                self::E_HEADER_MISMATCH,
                'Missing required MCP-Protocol-Version header',
                400,
            );
        }

        // The header must agree with the body. Divergence is a security
        // problem when a gateway routes on the header but the server
        // executes on the body.
        if ($meta !== null && $meta !== $header) {
            throw new JsonRpcException(
                self::E_HEADER_MISMATCH,
                'MCP-Protocol-Version header does not match _meta',
                400,
            );
        }

        if (!in_array($header, self::SUPPORTED_VERSIONS, true)) {
            throw new JsonRpcException(
                self::E_INVALID_REQUEST,
                sprintf('Unsupported protocol version "%s"', $header),
                400,
                ['supported' => self::SUPPORTED_VERSIONS],
            );
        }
    }

    /**
     * Mcp-Method is required on every request; Mcp-Name on tools/call,
     * resources/read and prompts/get. Both must match the body.
     */
    private function assertHeadersMatchBody(HTTPRequest $request, string $method, mixed $params): void
    {
        $headerMethod = $request->getHeader('Mcp-Method');
        if ($headerMethod === null) {
            throw new JsonRpcException(self::E_HEADER_MISMATCH, 'Missing Mcp-Method header', 400);
        }
        if ($headerMethod !== $method) {
            throw new JsonRpcException(
                self::E_HEADER_MISMATCH,
                sprintf('Mcp-Method header "%s" does not match body method "%s"', $headerMethod, $method),
                400,
            );
        }

        if ($method !== 'tools/call') {
            return;
        }

        $headerName = $request->getHeader('Mcp-Name');
        if ($headerName === null) {
            throw new JsonRpcException(self::E_HEADER_MISMATCH, 'Missing Mcp-Name header', 400);
        }

        $bodyName = is_array($params) ? ($params['name'] ?? null) : null;
        if ($this->decodeHeaderValue($headerName) !== $bodyName) {
            throw new JsonRpcException(
                self::E_HEADER_MISMATCH,
                'Mcp-Name header does not match body params.name',
                400,
            );
        }
    }

    /**
     * Header values that cannot be represented as plain ASCII arrive wrapped
     * in the sentinel format =?base64?...?=
     */
    private function decodeHeaderValue(string $value): string
    {
        if (!str_starts_with($value, '=?base64?') || !str_ends_with($value, '?=')) {
            return $value;
        }

        $payload = substr($value, 9, -2);
        $decoded = base64_decode($payload, true);

        return $decoded === false ? $value : $decoded;
    }

    private function originAllowed(string $origin): bool
    {
        $allowed = (array) static::config()->get('allowed_origins');

        return $allowed === [] || in_array($origin, $allowed, true);
    }

    // ---------------------------------------------------------------- output

    private function json(int $status, array $payload): HTTPResponse
    {
        $response = HTTPResponse::create(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
        );
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }

    private function error(
        mixed $id,
        int $code,
        string $message,
        int $status,
        ?array $data = null,
    ): HTTPResponse {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return $this->json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }

    private function plain(int $status, string $body): HTTPResponse
    {
        return HTTPResponse::create($body, $status);
    }
}
