<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Controllers;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Model\McpRequestLog;
use Sunnysideup\EcommerceMCP\Registry\ToolRegistry;
use Throwable;

/**
 * MCP endpoint implementing the Streamable HTTP transport, 2026-07-28
 * revision (stateless core).
 *
 * Deliberately has no session, no handshake and no persistent state: every
 * POST is self-contained, which maps cleanly onto PHP-FPM's process-per-request
 * model. Any instance behind a round-robin load balancer can serve any request.
 *
 * Two verbs are served at this one endpoint:
 *   - POST: the JSON-RPC transport (tools/list, tools/call, server/discover).
 *   - GET:  a public, cacheable discovery manifest that registries and crawlers
 *           can read cold. (This revision removed the GET SSE stream, so GET is
 *           free to carry the manifest.)
 *
 * Abuse protection (rate limiting, IP reputation, WAF) is handled at the edge
 * — Cloudflare in front, with the origin locked to Cloudflare's ranges. This
 * controller keeps only what belongs in the app: a short-lived result cache so
 * repeated identical catalogue queries don't re-hit the database.
 *
 * Spec: https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http
 */
class McpController extends Controller implements Flushable
{
    /** Protocol revisions this server speaks. */
    private const SUPPORTED_VERSIONS = ['2026-07-28'];
    private const DEFAULT_VERSION = '2025-11-25';
    private const META_NS = 'io.modelcontextprotocol/';

    /** JSON-RPC error codes. */
    private const E_PARSE            = -32700;
    private const E_INVALID_REQUEST  = -32600;
    private const E_METHOD_NOT_FOUND = -32601;
    private const E_INVALID_PARAMS   = -32602;
    private const E_INTERNAL         = -32603;
    private const E_VERSION_MISMATCH = -32020;

    private static array $allowed_actions = ['index'];

    private static array $allowed_origins = [];

    private static int $tools_list_ttl_ms = 3600000;

    /**
     * How long (seconds) a tools/call result is cached, keyed on tool name and
     * arguments. 0 (or less) disables caching. This is also the freshness bound:
     * an edit won't show to agents until the cached entry expires. Keep it short
     * enough that stale prices/stock are acceptable, or flush on publish.
     */
    private static int $result_cache_ttl_seconds = 300;

    public function index(HTTPRequest $request): HTTPResponse
    {

        // GET → public discovery manifest. Registries read this to learn the
        // endpoint and capabilities before ever issuing a JSON-RPC POST.
        if ($request->isGET()) {
            return $this->manifest($request);
        }

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

    // ---------------------------------------------------------------- discovery

    /**
     * Public discovery manifest served over GET. Reuses the same payload as the
     * JSON-RPC server/discover method and adds the transport details a cold
     * reader needs (where to POST, which verbs and protocol versions). Marked
     * cacheable so CDNs and crawlers don't re-fetch it on every visit.
     */
    private function manifest(HTTPRequest $request): HTTPResponse
    {
        $payload = $this->discover() + [
            'endpoint'                  => Director::absoluteURL($request->getURL()),
            'transport'                 => 'streamable-http',
            'methods'                   => ['POST'],
            'supportedProtocolVersions' => self::SUPPORTED_VERSIONS,
        ];

        $response = $this->json(200, $payload);
        $response->addHeader('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    // ---------------------------------------------------------------- routing

    private function dispatch(string $method, array $params): array
    {
        $registry = Injector::inst()->get(ToolRegistry::class);

        return match ($method) {
            'initialize' => $this->initialize($params),
            'server/discover' => $this->discover(),
            'tools/list'      => [
                'tools'      => $registry->definitions(),
                // Advertised so clients and gateways can cache the tool
                // surface instead of re-fetching it before every call.
                'ttlMs'      => static::config()->get('tools_list_ttl_ms'),
                'cacheScope' => 'public',
            ],
            'tools/call' => $this->callTool(
                $registry,
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
    private function initialize(array $params): array
    {
        // Agree on a protocol version. Echo the client's if we speak it; otherwise
        // answer with ours and let the client decide whether to proceed.
        $clientVersion = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($clientVersion, self::SUPPORTED_VERSIONS, true)
            ? $clientVersion
            : self::SUPPORTED_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'serverInfo'      => [
                'name'    => 'catalogue',
                'title'   => 'Product Catalogue',
                'version' => '1.0.0',
            ],
            'capabilities'    => [
                'tools' => new \stdClass(),
            ],
            'instructions' => 'Search this catalogue with search_products before '
                . 'answering questions about availability or price. Always cite the '
                . 'returned url when recommending a product.',
        ];
    }
    /**
     * Execute a tool (via the result cache) and record the call. Logging runs
     * on every request — cache hit or miss — because the agent asked either way;
     * only the DB-hitting execution is short-circuited by the cache.
     *
     * The log write is wrapped so a DB failure degrades to a warning rather than
     * turning a good tool response into a 500 — an audit trail must never be
     * load-bearing for the response.
     */
    private function callTool(ToolRegistry $registry, string $name, array $arguments): array
    {
        // Force the live stage before anything reads products, so drafts can
        // never leak to an agent — and so nothing draft-shaped enters the cache.
        Versioned::set_stage(Versioned::LIVE);

        $result = $this->executeCached($registry, $name, $arguments);

        try {
            McpRequestLog::track(
                (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->countResults($result),
                $name,
            );
        } catch (Throwable $e) {
            user_error('MCP log write failed: ' . $e->getMessage(), E_USER_WARNING);
        }

        return $result;
    }

    // ------------------------------------------------------------ result cache

    /**
     * Return a cached result for this (name, arguments) pair, or execute and
     * cache it. Cache read/write failures never break the call — a broken cache
     * degrades to "always execute", not to an error.
     */
    public function executeCached(ToolRegistry $registry, string $name, array $arguments): array
    {
        $ttl = (int) static::config()->get('result_cache_ttl_seconds');
        if ($ttl <= 0) {
            return $registry->call($name, $arguments); // caching disabled
        }

        $cache = static::resultCache();
        $key   = $this->cacheKey($name, $arguments);

        try {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
            // fall through to execution on any read failure
        }

        $result = $registry->call($name, $arguments);

        // Only cache clean results. A transient in-band error (unknown SKU,
        // etc.) shouldn't stick around for the whole TTL.
        if (empty($result['isError'])) {
            try {
                $cache->set($key, $result, $ttl);
            } catch (Throwable $e) {
                // a failed cache write is not a failed request
            }
        }

        return $result;
    }

    private static function resultCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.mcpResultCache');
    }

    /**
     * A deterministic cache key for a tool call. Argument key order is
     * normalised so {query, limit} and {limit, query} hit the same entry; the
     * *value* order of list arguments (e.g. category_ids) is left alone, so
     * [1,2] and [2,1] are distinct entries — a minor cache miss, never a
     * correctness issue.
     */
    private function cacheKey(string $name, array $arguments): string
    {
        $canonical = $this->canonicaliseKeys($arguments);

        return 'mcp_call_' . sha1($name . '|' . json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    private function canonicaliseKeys(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonicaliseKeys($v);
            }
        }

        return $data;
    }

    /**
     * Best-effort "number of results provided" across the tool surface: a
     * results/categories/brands list is counted; an error payload is zero; a
     * single object (e.g. get_product) is one.
     */
    private function countResults(mixed $result): int
    {
        if (!is_array($result)) {
            return 0;
        }
        // The registry wraps tool output; look at the structured payload.
        $payload = $result['structuredContent'] ?? $result;
        if (!is_array($payload)) {
            return 0;
        }
        foreach (['results', 'categories', 'brands'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return count($payload[$key]);
            }
        }
        return isset($payload['error']) ? 0 : 1;
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

    // /**
    //  * The transport spec expects an MCP-Protocol-Version header, but a terse
    //  * client that omits it isn't ambiguous when we speak exactly one version —
    //  * so a missing header defaults rather than being rejected. What we still
    //  * reject: a version we don't speak, or a header that contradicts _meta
    //  * (which would mean a confused or tampering intermediary).
    //  */
    // private function assertProtocolVersion(HTTPRequest $request, mixed $params): void
    // {
    //     $header = $request->getHeader('MCP-Protocol-Version') ?? self::SUPPORTED_VERSIONS[0];
    //     $meta   = is_array($params) ? ($params['_meta'][self::META_NS . 'protocolVersion'] ?? null) : null;

    //     if ($meta !== null && $meta !== $header) {
    //         throw new JsonRpcException(
    //             self::E_VERSION_MISMATCH,
    //             'MCP-Protocol-Version header does not match _meta',
    //             400,
    //         );
    //     }

    //     if (!in_array($header, self::SUPPORTED_VERSIONS, true)) {
    //         throw new JsonRpcException(
    //             self::E_INVALID_REQUEST,
    //             sprintf('Unsupported protocol version "%s"', $header),
    //             400,
    //             ['supported' => self::SUPPORTED_VERSIONS],
    //         );
    //     }
    // }

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

    public static function flush()
    {
        // Implement flush logic here if needed
        static::resultCache()->clear();
    }
}
