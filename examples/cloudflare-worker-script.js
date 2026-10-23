// Cloudflare Worker script for caching POST requests of the MCP server endpoint
// Set routes to your.server.com/mcp/*

export default {
  async fetch(request, env, ctx) {
    // 1. Only process POST requests; pass others through normally
    if (request.method !== "POST") {
      return fetch(request);
    }

    // 2. Clone the request so we can read the body without consuming it
    const clonedRequest = request.clone();
    const bodyText = await clonedRequest.text();

    // 3. Generate a SHA-256 hash of the POST body
    const encoder = new TextEncoder();
    const hashBuffer = await crypto.subtle.digest("SHA-256", encoder.encode(bodyText));
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const hashHex = hashArray.map(b => b.toString(16).padStart(2, "0")).join("");

    // 4. Create a unique URL for the cache key by appending the hash
    const url = new URL(request.url);
    url.pathname = `/post-cache/${hashHex}${url.pathname}`;

    // 5. Construct a GET request to serve as the cache key
    const cacheKey = new Request(url.toString(), {
      headers: request.headers,
      method: "GET",
    });

    const cache = caches.default;

    // 6. Check if we already have a cached response
    let response = await cache.match(cacheKey);

    if (!response) {
      // Cache miss: Fetch from origin
      response = await fetch(request);

      // Read what the origin server wants us to do
      const cacheControl = response.headers.get("Cache-Control") || "";

      // Only attempt to cache if the origin says 'public'
      if (cacheControl.toLowerCase().includes("public")) {

        // Reconstruct the response so headers can be modified if needed
        let cacheableResponse = new Response(response.body, response);

        // The Cache API requires a max-age directive to store the object.
        // If your origin only sends 'public' without a max-age, we must append one.
        if (!cacheControl.includes("max-age") && !cacheControl.includes("s-maxage")) {
          cacheableResponse.headers.set("Cache-Control", `${cacheControl}, s-maxage=3600`);
        }

        // Store in cache
        ctx.waitUntil(cache.put(cacheKey, cacheableResponse.clone()));

        return cacheableResponse;
      }

      // If it's 'no-cache', 'no-store', or missing 'public', just return it directly without caching
      return response;

    } else {
      // Cache hit
      response = new Response(response.body, response);
      response.headers.set("X-Post-Cache-Status", "HIT");
      return response;
    }
  }
};
