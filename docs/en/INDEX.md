# Silverstripe MCP Catalogue Server

A stateless MCP endpoint that lets AI agents query your product catalogue
instead of downloading a 6MB feed.

Implements the **Streamable HTTP** transport, **2026-07-28** revision: no
`initialize` handshake, no `Mcp-Session-Id`, every request self-contained.
That maps exactly onto PHP-FPM's process-per-request model, so this runs
behind a plain round-robin load balancer with no sticky sessions and no
shared session store.

## Classes

```
McpController       Transport: JSON-RPC, header validation, dispatch
ToolProvider        Interface for anything exposing tools
ToolRegistry        Tool definitions + tools/call routing
JsonRpcException    Error carrying both a JSON-RPC code and HTTP status
CatalogueTools      The four catalogue tools
```

Drop `src/` and `_config/` into your `app/` directory, then
`vendor/bin/sake dev/build flush=1`.


## Search performance

`searchProducts()` ships with `PartialMatch`, which compiles to
`LIKE '%term%'` and cannot use an index. It is there so the module runs
out of the box, not because it is correct. Before you put this in front of
real traffic, replace the filter block with a call into whatever you already
run:

- **MySQL fulltext** — add a `FULLTEXT` index and use a raw `WHERE MATCH()
  … AGAINST()` via `->where()`.
- **Solr / Elastic** — query the index, collect IDs, then
  `Product::get()->byIDs($ids)` and re-sort to the index's relevance order.

The rest of the tool (filters, ceilings, response shaping) is unaffected.

## Testing it

The transport is plain HTTP, so curl is enough. Note that `Mcp-Method` and
`Mcp-Name` are mandatory and are validated against the body — a mismatch
returns `400` with JSON-RPC code `-32020`.

```bash
# Capability discovery (replaces the old initialize handshake)
curl -sS https://example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: server/discover' \
  -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{}}' | jq

# List tools
curl -sS https://example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/list' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | jq

# Call a tool
curl -sS https://example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: search_products' \
  -d '{
        "jsonrpc":"2.0","id":3,"method":"tools/call",
        "params":{
          "name":"search_products",
          "arguments":{"query":"merino","in_stock_only":true,"limit":5},
          "_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28"}
        }
      }' | jq
```

## Deployment notes

**nginx.** Rate-limit the endpoint. An unauthenticated search API is a gift
to competitor price-scrapers.

```nginx
limit_req_zone $binary_remote_addr zone=mcp:10m rate=30r/m;

location = /mcp {
    limit_req zone=mcp burst=10 nodelay;
    try_files $uri /index?$query_string;
}
```

**Auth.** A public catalogue is public data, so this ships unauthenticated,
which saves an enormous amount of work. The moment you add cart or order
tools that changes: the 2026-07-28 revision formally positions MCP servers
as OAuth 2.1 resource servers, and you will need to implement that properly
rather than bolting on an API key.

**Origins.** `allowed_origins` is empty-by-default, which permits everything.
Populate it if you care about DNS-rebinding protection; requests with no
`Origin` header at all (ordinary server-to-server agent traffic) always pass.

## Getting discovered

Building it is half the job.

1. List the server in the MCP Registry.
2. Reference the endpoint in `llms.txt`:
   `- [Product catalogue MCP server](https://example.com/mcp): search products, check stock`
3. Keep the JSON-LD `Product`/`Offer` markup on your PDPs. Most agents still
   crawl HTML directly and will never call this endpoint.

## If you would rather not own the transport

`McpController` is ~250 lines of protocol plumbing. Two PHP SDKs will do it
for you:

- `mcp/sdk` — the official PHP SDK, a PHP Foundation / Symfony
  collaboration. Framework-agnostic, still marked experimental pre-1.0.
- `logiscape/mcp-sdk-php` v2 — day-one 2026-07-28 support with automatic
  version negotiation back to 2024-11-05, so one codebase serves clients on
  old and new revisions.

If you swap one in, `CatalogueTools` carries over unchanged — that is why
the tools are isolated behind `ToolProvider`. Verify the current release on
Packagist first; this ecosystem is moving weekly.

## configuration

Here is how to config the 

```yml
Sunnysideup\EcommerceMCP\Tools\CatalogueTools:
  category_ttl_ms: 3600000 #how long before categories are stale

  product_class: Sunnysideup\Ecommerce\Pages\Product
  category_class: Sunnysideup\Ecommerce\Pages\ProductGroup
  
  product_stock_methods: 
    - MyStockLevels

  product_stock_sql_field: 'StockInt'
  private static int $default_product_stock_methods_value: 10

  product_brand_methods:
    - 'getBrand'

  product_brand_methods_sql_field: 'Brand.ID'
  
  default_product_brand_methods_value: 'No Brand'

  product_category_methods: 
    - MyCategoryName
  product_category_methods_sql_field: 'ParentID'
  default_product_category_methods_value: 'ParentID'
```
