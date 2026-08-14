# Caching, and the Blitz recipe

Caffeine is designed to sit behind a full-page cache. This is how, and — more usefully — why each
piece is the way it is.

If you only want the configuration, skip to §4.

---

## 1. The three problems

"Does it work with Blitz" hides three separate questions, and they have three separate answers.

### The URL in the HTML goes stale

Cached HTML cannot contain `/caffeine/products-v42.json`, because v43 exists ten minutes later and
the cached page still asks for v42.

So the page embeds a **stable pointer** — `current.json` — and never a versioned URL. It is a few
hundred bytes, and it names the current payload. The payload's own filename is a hash of its
contents, so it can be cached forever.

That inverts the usual caching advice, and deliberately: the small file is uncached and the large
one is immutable.

```
current.json                    Cache-Control: no-cache
{hash}.index.json               Cache-Control: public, max-age=31536000, immutable
```

Superseded payloads are kept — `keepVersions`, default 3 — because a visitor who loaded the page a
moment before a rebuild is still fetching the previous one. Pruning it out from under them turns a
rebuild into a 404.

### The first paint depends on the query string

If the static cache key ignores the query string, a request for `?brand=acme` gets HTML rendered
for no refinements. Two ways to handle it, and they suit different sites.

**Cache the canonical page only** (recommended). The cached page is always the unrefined state.
The runtime notices that the URL and the rendered state disagree and refines before paint, setting
`data-caffeine-hydrating` on the wrapper while it does. One cache entry per listing, and the
refinement costs one small request.

**Or include the query string in the cache key.** Every refinement is then server-rendered and
cached — fastest for the visitor, but a listing with five facets has a combinatorial number of
URLs and the cache fills with permutations nobody asked for twice.

Either way, emit a canonical URL so search engines index one page rather than forty thousand:

```twig
<link rel="canonical" href="{{ search.canonicalUrl }}">
```

Facet links carry `rel="nofollow"` already.

### No JavaScript at all

Every control is a real link or a real form. With the runtime absent they are ordinary page loads
that server-render correctly, which is also why a crawler sees a working site.

---

## 2. What must not be cached

| Path | Why |
| --- | --- |
| `/caffeine/*/current.json` | The pointer. Caching it is what makes a rebuild invisible. |
| `/caffeine/fragment` | Depends entirely on the query string. |

Everything else under the publish path is content-addressed and safe to cache forever.

---

## 3. Serving the artifact well

Caffeine writes `.gz` and `.br` sidecars next to every artifact. Point your web server at them so
it serves the compressed copy without recompressing on each request.

**nginx**

```nginx
location /caffeine/ {
    gzip_static on;
    brotli_static on;

    location ~ /current\.json$ {
        add_header Cache-Control "no-cache";
    }

    location ~ \.(index|payload)\.json$ {
        add_header Cache-Control "public, max-age=31536000, immutable";
    }
}
```

**Apache** — `mod_deflate` will not serve precompressed files on its own; use `mod_rewrite` to
prefer them, or set `precompress` to false in the plugin settings and let Apache compress on the
fly.

**A CDN in front** is ideal and needs no special handling: the immutable files are the ones worth
caching at the edge, and they are already immutable.

---

## 4. Blitz

```php
// config/blitz.php
return [
    'cachingEnabled' => true,

    // The listing is cached in its canonical, unrefined state. The runtime refines from there.
    'queryStringCaching' => 0,

    'excludedUriPatterns' => [
        ['siteId' => '', 'uriPattern' => 'caffeine/fragment'],
    ],
];
```

`queryStringCaching => 0` is the important line: it tells Blitz to cache the URI without the query
string, which is exactly the canonical state described above.

If you would rather server-render refined states, use `2` (cache URLs with query strings as
separate entries) and accept the cache-size trade.

### Refreshing

Blitz's own element tracking already refreshes the page when the underlying entries change, so
nothing extra is needed. What Blitz cannot see is a Caffeine **republish** with no element change
— a rebuilt index after a definition edit, for instance. That only matters if you are caching
refined states, because the canonical page's markup does not depend on the artifact's contents.

If you are, refresh the listing after a publish:

```php
use justinholtweb\caffeine\services\Artifacts;

// In a module's init(), after Caffeine publishes:
Craft::$app->onAfterRequest(function() {
    // Blitz::$plugin->refreshCache->refreshCachedUrls(['https://example.com/products']);
});
```

### Static file caching

Blitz's static file storage works unchanged. The pointer lives under the publish path, not under
Blitz's cache, so nothing collides.

---

## 5. Craft's own template caching

`{% cache %}` around a `{% caffeine %}` block will cache the *rendered state*, including the
refinements from whatever query string happened to be in the first request that populated it.
Don't. There is nothing to gain — the block is already rendered from an artifact, not from element
queries, and that is the expensive thing `{% cache %}` exists to avoid.

---

## 6. Checking it works

```sh
# The pointer should be uncached and small.
curl -sI https://example.com/caffeine/products/current.json | grep -i cache-control

# The payload it names should be immutable.
curl -s https://example.com/caffeine/products/current.json | jq -r '.shards.index.url'
```

And in the browser: load the canonical URL, then load it again with `?brand=acme`. The second
should show refined results even though the HTML came from cache — that is the hydration path
doing its job, and `data-caffeine-hydrating` on the wrapper is how you can see it happen.
