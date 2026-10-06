# StaticSitemaps — Architecture

**Status:** built (v0.1.0). Amended during implementation — see §18. Companion to [`goals.md`](goals.md); requirement numbers (R*) and decisions
(D*) refer to that document.

---

## 1. Module identity and dependencies

| | |
|---|---|
| Directory / namespace / module ID | `StaticSitemaps` (D15) |
| Omeka S | `^4.0` |
| Hard dependencies | **none** |
| Soft integrations | `AdvancedMedia`, `AdvancedSearch` — runtime-detected, absent-safe (§4, D1) |

The name avoids collision with the existing third-party `Sitemaps` module, whose directory name
would otherwise be the same namespace and module ID.

## 2. The shape of the problem

Everything this module does is one pipeline, run inside a job, against a set of enabled
(site × content-type) pairs:

    SOURCES  →  FILTERS  →  ENRICHERS  →  WRITER  →  PUBLISH  →  REPORT
    produce     reject      decorate      serialise  atomic     what happened
    entries     entries     entries       to disk    rename

Each stage has exactly one responsibility, and the seams are where extension happens:

- **Sources** answer *"what URLs exist?"* — one per content type, contributed through config so
  another module can add its own (§5).
- **Filters** answer *"may this URL be published?"* — privacy (R5) and exclusions (R3.6). They
  reject; they never rewrite.
- **Enrichers** answer *"what else does this entry carry?"* — currently only media extensions
  (§7). They decorate; they never add or remove entries.
- **Writer** serialises to XML and manages the split across files and the index (§8).
- **Publish** makes the result visible atomically (§9).
- **Report** records what happened for the admin UI (§11).

**Media is an enricher, not a source.** This falls directly out of D9: media are emitted as
`<image:image>`/`<video:video>` elements nested inside their parent item's `<url>` entry, so
they never produce entries of their own. That is also why media is a global switch (D18) — it
modifies how item entries are written, not which sites contribute.

## 3. Why a pipeline of generators, not a collected array

The largest realistic run here is ~12,000 item entries, each potentially carrying media
extensions. Building that in memory before writing would be an avoidable multi-hundred-megabyte
array in a process whose memory limit is a known-sensitive setting on this project.

**Every stage is a PHP generator.** Sources `yield` entries; filters and enrichers are generators
that consume and re-`yield`; the writer consumes the final stream and writes as it goes. Peak
memory is one entry plus a database page, regardless of collection size. This is a hard
architectural rule, not an optimisation to add later: it is very difficult to retrofit once any
stage returns arrays.

Database access is paginated (API `limit`/`page`), so the DAL never materialises the full result
set either.

## 4. Soft integration, in practice

Both integrations are detected, never required.

**`AdvancedMedia` — zero coupling by design.** Media URLs come from
`MediaRepresentation::originalUrl()`, which resolves through the file store's `getUri()`. That is
precisely the method `AdvancedMedia\File\Store\ProxyingAwsS3` overrides. So on an installation
where proxying is on we get rewritten URLs **for free, with no reference to the module at all**;
on a stock installation we get ordinary store URLs. There is no `class_exists` check and no
adapter — the correct URL is whatever the store says it is.

> **⚠️ One cross-module hazard must be checked, though.** `AdvancedMedia`'s proxying store
> self-disables when it cannot find an absolute base URI, and background jobs have no request —
> which is exactly how S3 URLs previously got baked into the IIIF manifest cache and the Solr
> index on this installation. If media enrichment is on, `AdvancedMedia` is present with
> proxying enabled, and its `advancedmedia_files_base_uri` is blank, this module must **warn on
> the config page and refuse the run**, because it would silently publish S3 URLs. See §12.

**`AdvancedSearch`** is a genuine conditional: the `SearchPagesSource` is registered only when the
module is present, and the per-site checkbox is hidden when it is not (R3.3).

## 5. Sources

### 5.1 Interface (sketch)

```php
interface UrlSourceInterface
{
    public function getLabel(): string;          // for the config matrix
    public function isAvailable(): bool;         // soft-integration gate
    public function countFor(SiteRepresentation $site): int;    // R6, cheap
    public function entriesFor(SiteRepresentation $site): iterable; // yields UrlEntry
}
```

`countFor()` is separate from `entriesFor()` and must stay cheap: the config page calls it for
every site × type on every load (R6). It uses the API's total-results count, never URL
construction.

### 5.2 Registry

Sources are registered through module config, mirroring the pattern `AdvancedMedia` uses for
players and `resource_page_block_layouts` uses in core:

```php
'staticsitemaps_url_sources' => [
    'factories' => [
        'site-pages'    => Service\Source\SitePagesSourceFactory::class,
        'items'         => Service\Source\ItemsSourceFactory::class,
        'item-sets'     => Service\Source\ItemSetsSourceFactory::class,
        'search-pages'  => Service\Source\SearchPagesSourceFactory::class,
    ],
],
```

Another module adds a source by adding a key. The config matrix is generated from the registry,
so a new source needs no UI work.

### 5.3 The v1 roster

| id | scoping mechanism | default | notes |
|---|---|---|---|
| `site-pages` | `site_page.site_id`, `is_public = 1` | off | `lastmod` from `site_page.modified` |
| `items` | **`item_site`** join via API `site_id` | off | see 5.4 |
| `item-sets` | **`site_item_set`** join via API `site_id` | **off** (D6) | different table from items — confirmed in `ItemSetAdapter` |
| `search-pages` | `advancedsearch_configs` site setting | off | all configs targeted at the site (D10) |

The two resource sources deliberately use **different** join tables, because Omeka scopes them
differently: `ItemAdapter` joins `item_site`, `ItemSetAdapter` joins `siteItemSets`. Using the
API's `site_id` query for both means we inherit whichever is correct rather than reimplementing
it.

### 5.4 Items: read membership verbatim (D7)

`ItemsSource` queries `['site_id' => N, 'is_public' => true]` and emits what comes back. It does
**not** consult the site's `item_pool`, does not special-case an empty pool, and does not
deduplicate across sites (D8). An empty pool legitimately means "all items"; a site listing
3,589 items is a fact about that site, surfaced by R6 at configuration time rather than
overridden here.

## 6. Filters

Filters are generators that drop entries. Two ship in v1, in this order:

### 6.1 Privacy (R5) — belt and braces

Privacy is enforced **twice, deliberately**:

1. **At the query.** Every source passes `is_public => true`, and site-level enumeration only
   ever visits public sites.
2. **At the filter.** A `PrivacyFilter` re-checks each entry's resource before it is written.

The redundancy is not defensive habit; it is the specific lesson from the `item_site` finding.
That table is populated by core's `UpdateSiteItems` job *with `view-all` explicitly granted*, so
it contains private items — 823 of them on public sites here. A generation job running under an
administrator's identity is equally entitled to see them through the API. **Privacy must never be
an emergent property of the caller's privileges.** The second check costs one boolean per entry
and makes the guarantee structural.

### 6.2 Exclusions (R3.6, D11)

Prefix match against the **local path**, case-insensitive, trailing slashes normalised. Matching
the local path rather than the absolute URL keeps the rules independent of the configured base
URL — an administrator types `/s/monsters/item`, not the full origin.

The filter counts rejections **per rule** so the UI can report "this rule removed 0 URLs", which
is how a typo announces itself instead of silently doing nothing.

Documentation must state plainly that this **guides crawlers, it does not control them** — an
excluded URL is merely absent from the sitemap and remains fully reachable. The UI copy carries
the same warning next to the field.

## 7. The media enricher (D9, D18)

Global switch, off by default. When on, `MediaEnricher` decorates each **item** entry with media
extensions:

- **Scope** — two options, both defaulting on: *primary media only*, and *originals only*.
- **Type mapping** — image MIME types become `<image:image>`, video becomes `<video:video>`.
  Anything else is skipped: a sitemap has no element for a PDF or a VTT sidecar, and inventing
  one is worse than omitting it.
- **Sanity filters** — `hasOriginal()` and non-zero size, which also drops the zero-byte
  `x-empty` records known to exist in this collection.
- **URL** — `originalUrl()`, per §4.
- The extension namespaces are declared on the root element only when at least one entry will
  carry them.

**Noted as coarse on purpose** (goals R3.4): real installations will want per-MIME, per-class and
per-site control. The enricher interface should keep that door open; v1 does not build it.

## 8. Writing: entries, files, and the index

### 8.1 The entry

```php
final class UrlEntry
{
    public string $path;              // local, e.g. "/s/monsters/item/4403"
    public ?DateTimeInterface $lastmod;
    public array $images = [];        // absolute URLs
    public array $videos = [];
}
```

Entries carry the **local path**, not an absolute URL. Absolutisation happens once, in the
writer, via the `UrlBuilder` (§10). That keeps exclusion matching simple, keeps sources ignorant
of deployment, and means a base-URL change cannot leave half the pipeline stale.

`<lastmod>` only — no `<changefreq>`, no `<priority>` (D13). It comes from the real
`resource.modified` / `site_page.modified` value and is **never synthesised from the run time**;
a fabricated `lastmod` is worse than none. Entries with no real timestamp (search pages) simply
omit the element.

### 8.2 Splitting

Children are written per **site × source**, rolling to a new file when either limit is reached:

- 50,000 entries (protocol limit; configurable lower)
- 50 MB uncompressed (protocol limit) — tracked as bytes are written, not estimated

Names are stable and predictable: `sitemap-{site-slug}-{source-id}-{n}.xml`.

**Filenames are not versioned per run** — decided. The index URL must stay stable because
`robots.txt` advertises it, and versioned children would trade a purge problem for a
retention-and-churn problem. Left open as a future option (goals §9).

### 8.3 The index

**Always emitted, even with a single child** (D12), so the published URL never changes shape as
the collection grows. `sitemap.xml` is the index; it is the only file `robots.txt` names and the
only one submitted to a search console.

### 8.4 Serialisation

`XMLWriter` in streaming mode against a file handle — not `DOMDocument` (which builds a tree in
memory) and not string concatenation (which invites escaping bugs). URLs are XML-escaped by the
writer; they are separately URL-encoded when built.

## 9. Publishing atomically

**A crawler must never see a half-written sitemap, and a failed run must never destroy a good
one.**

1. Write the whole set into a sibling temp directory in the target location.
2. `fsync`, then `rename()` each file into place — atomic within a filesystem.
3. Rename the index **last**, so it never references a child that is not yet in place.
4. Remove children from the previous run that the new run did not produce.

A run that throws leaves the previous sitemap wholly intact. This matters more than usual here
because the output is served by the web server with no application in the path — there is nothing
to catch an error at request time.

**Concurrency:** a lock prevents overlapping runs (cron firing while an admin-triggered run is in
flight). A stale lock older than a configurable age is broken, so a killed job cannot wedge the
feature permanently.

### 9.1 Target locations (R3.7, D19)

| layout | index | children | document-root footprint |
|---|---|---|---|
| `docroot` | `OMEKA_PATH/sitemap.xml` | beside it | one file per site × source |
| `docroot-subdir` | `OMEKA_PATH/sitemap.xml` | `OMEKA_PATH/sitemaps/` | one file, one directory |
| `files` | `OMEKA_PATH/files/sitemaps/sitemap.xml` | same directory | none |

`Location` therefore exposes **index** and **child** directories and public prefixes
separately; they coincide for two of the three layouts and diverge for `docroot-subdir`.

These are **local filesystem paths, deliberately not the Omeka file store.** On an
S3-backed installation the store would put the sitemap in the bucket, where the web server
cannot serve it statically — the exact opposite of the point. The store abstraction is the
wrong tool here, and reaching for it is the natural mistake.

**Choosing between them is a clutter-versus-convention trade**, not a correctness one. The
first two put the index at `/sitemap.xml`; `files` is for document roots that are not
writable, and costs both the conventional path and a collision with the `/files/` path
space that media bot-control rules target.

**The scope rule applies to all three.** Under the sitemaps.org protocol a sitemap's
location bounds the URLs it may contain, so strictly only the flat `docroot` layout is
independent of `robots.txt`. This is why the module surfaces the `Sitemap:` directive so
prominently rather than treating it as polish.

### 9.2 Writability preflight (R3.7.1)

`TargetProbe` runs on config-page load and before a job starts:

- **Hard gate — writability.** Directory exists, is writable, and a token file can be created and
  removed. Failure refuses the setting with a plain-language explanation, rather than letting the
  job fail later. The docroot is commonly root-owned on real servers; it is writable in this dev
  stack only because of the Docker bind mount.
- **Soft check — is it actually served?** Optionally fetch the token file over the configured base
  URL to confirm the path is web-visible. This is a **warning, never a gate**: `AdvancedMedia`'s
  reachability work established that a server cannot always reach its own public URL (split-horizon
  DNS, egress rules, container port mapping), and a sitemap that the server cannot self-fetch may
  still be perfectly visible to the outside world.

## 10. URL construction without a request (R4)

Background jobs have no HTTP request, so `ServerUrl` has nothing to detect from. This has already
produced wrong stored URLs twice on this installation. The architecture removes the failure mode
rather than documenting around it:

```php
final class UrlBuilder
{
    // Built from the configured base URL. Throws if unconfigured.
    public function __construct(string $baseUrl) { ... }
    public function absolutise(string $localPath): string;
}
```

- Sources produce **local paths** from route assembly / `siteUrl()` — never absolute URLs.
- The writer absolutises exactly once, through `UrlBuilder`.
- `ServerUrl` is not used anywhere in the generation path. **This is a rule to enforce in review.**
- **Generation refuses to start** when no base URL is configured — it does not guess, and does not
  fall back to `localhost`.

## 11. The job and its triggers (R2)

### 11.1 The job

`StaticSitemaps\Job\GenerateSitemaps`, a standard `Omeka\Job\AbstractJob`. It resolves
configuration, runs the pipeline, publishes, and writes a **run report** to settings:

    timestamp · duration · per-site/per-source counts · exclusion hits per rule ·
    private items excluded · files written · warnings · error (if any)

The report is what the admin UI displays (R5.1, D14) — "last generated", what was included, and
crucially **what was left out and why**.

### 11.2 Triggers

- **Admin button (R2.1)** — dispatches through `Omeka\Job\Dispatcher` like any Omeka job, so it
  is visible and logged on the standard job pages.
- **Cron (R2.2)** — the module ships its own small CLI entry point,
  `data/scripts/generate-sitemaps.php`. It **must** bootstrap via
  `\Omeka\Mvc\Application::init(...)`; a plain Laminas init does not load active addon modules,
  which would silently disable the `AdvancedMedia` store override and the `AdvancedSearch` source.
  It takes a user id (for job ownership) and reads the base URL from configuration.

  Shipping our own script keeps cron support dependency-free for the community build. Where
  `EasyAdmin` happens to be installed its generic `data/scripts/task.php` runner works too, and
  the README should mention it as an alternative — but never as a requirement.

  **Run it as the web server user** (`www-data` or equivalent). A run as `root` will create
  sitemap files the web server may not be able to replace on the next run.

## 12. Configuration

### 12.1 Storage

Global settings in `Omeka\Settings`; per-site inclusion flags in `Omeka\Settings\SiteSettings`,
even though they are *edited* from the global matrix. Site-scoped storage means a per-site admin
page can be added later with no migration, and site deletion takes its rows with it.

Writes follow `AdvancedMedia`'s `ConfigWriter` conventions — merge-preserving writes so an absent
POST key never silently clears stored state.

| key | scope | default |
|---|---|---|
| `staticsitemaps_base_url` | global | *(unset — generation refuses)* |
| `staticsitemaps_target` | global | `docroot` |
| `staticsitemaps_include_media` | global | `0` |
| `staticsitemaps_media_primary_only` | global | `1` |
| `staticsitemaps_media_originals_only` | global | `1` |
| `staticsitemaps_all_items` / `_all_pages` | global | `0` (R3.5) |
| `staticsitemaps_exclusions` | global | `''` |
| `staticsitemaps_max_entries` | global | `50000` |
| `staticsitemaps_last_run` | global | *(report)* |
| `staticsitemaps_sources` | **site** | `[]` — per-source `'1'`/`'0'` |

Per-site values are `'1'`/`'0'` strings rather than booleans, following the lesson recorded in
`AdvancedMedia`: a checkbox cannot express three states, and `''` must stay available to mean
"not set" without colliding with "off".

### 12.2 The matrix (R3.3, D8)

One global page listing every site, four checkboxes each (Pages / Items / Item sets / Advanced
Search pages), each showing the URL count it contributes (R6). Media is absent from the matrix —
it is global-only (D18).

The global "all items" / "all site pages" switches (R3.5) supersede the matrix when on; the UI
must **visibly disable** the superseded controls rather than quietly ignoring them.

### 12.3 Cross-checks shown on the config page

The config page is where problems surface, because the output has no request-time error path:

- writability of the chosen target (§9.2) — hard
- base URL configured (§10) — hard
- **`AdvancedMedia` base-URI check** (§4) — hard when media enrichment is on
- `robots.txt` audit (§13)
- per-site and per-source counts, plus what privacy excluded

## 13. robots.txt (R3.8, R3.9)

Three capabilities, deliberately ordered by how safe they are:

1. **Show the literal line (R3.8.1, D5).** Always available, always shown:

       Sitemap: https://example.org/sitemap.xml

   computed from the actual target and base URL, ready to copy. `robots.txt` is frequently
   version-controlled (it is here, in the `codebase` repo), read-only, or managed outside the
   application, so writing to it can never be the only path.

2. **Optional append (R3.8.2).** Subject to the same writability preflight, idempotent (never
   duplicates an existing directive), and preserving the rest of the file.

3. **Audit (R3.9).** Parse `robots.txt` and answer two questions:
   - *Is the sitemap discoverable?* Is there a `Sitemap:` directive, and does it point at the
     index we actually write?
   - *Is anything we publish blocked?* Match published paths against `Disallow` rules. **This is
     the consistency check** — advertising a URL that the crawler is then told not to fetch is
     worse than not listing it, and it surfaces as coverage errors in search consoles.

   The matcher implements the parts of the convention that matter: user-agent grouping, `Allow`
   precedence, `*` and `$` wildcards. Reported as counts with examples, not a wall of URLs.

   On this installation the existing rule is `Disallow: /s/*/discover?*`, which blocks only the
   **query-string** forms. Bare landing pages stay crawlable, so listing Advanced Search pages is
   consistent with it — and if that pattern is ever tightened to `/s/*/discover`, this audit is
   what catches the contradiction.

## 14. Edge caching (R7)

The module does not talk to any CDN. It emits headers guidance and documentation:

- **Short TTL, not long.** Worst-case staleness is `regeneration interval + edge TTL`, so a 24h
  TTL on a nightly rebuild yields ~48h of stale sitemap. ~1h is the recommended default; sitemap
  fetches are rare and the files are small, so it costs effectively nothing.
- **`ETag`/`Last-Modified`** make expiry cheap — an expired edge copy costs a conditional `GET`
  and a `304`, which is what makes a short TTL free.
- **Documentation covers Cloudflare, CloudFront and nginx** (D17). Note that Cloudflare's default
  failure mode is the *opposite* of staleness: `.xml` is not among its default cached extensions,
  so sitemaps are likely not edge-cached at all without a cache rule. CloudFront honours origin
  `Cache-Control` within its policy's TTL bounds, and its invalidations are metered — which is
  why purge-after-every-rebuild is not the recommended pattern.
- **Apache note for this stack:** `mod_headers` and `mod_deflate` are present, `mod_expires` is
  **not** — guidance must use `Header set Cache-Control`.
- **Traffic shaping is the administrator's business** (R7.5). We document the caching integration
  we own and the consistency principle; we ship no bot rules.

## 15. Proposed file layout

    StaticSitemaps/
      Module.php
      config/module.config.php
      data/scripts/generate-sitemaps.php
      src/
        Job/GenerateSitemaps.php
        Generator/Pipeline.php
        Generator/UrlEntry.php
        Generator/UrlBuilder.php
        Generator/RunReport.php
        Source/UrlSourceInterface.php
        Source/{SitePages,Items,ItemSets,SearchPages}Source.php
        Source/Manager.php
        Filter/PrivacyFilter.php
        Filter/ExclusionFilter.php
        Enricher/MediaEnricher.php
        Writer/SitemapWriter.php
        Writer/IndexWriter.php
        Writer/AtomicPublisher.php
        Target/TargetProbe.php
        Robots/RobotsFile.php
        Robots/RobotsAuditor.php
        Config/ConfigOptions.php
        Config/ConfigWriter.php
        Controller/Admin/IndexController.php
        Service/...            (factories, mirroring the tree)
      view/
      docs/{goals,architecture}.md
      README.md

## 16. Verification approach

Following the pattern established on `AdvancedMedia`: throwaway harness scripts run in-container,
bootstrapped through `\Omeka\Mvc\Application::init()` so active modules load. Specific things
worth harnessing early, because each has already been a source of error on this project:

1. **Privacy** — assert zero private resources in the output, against the 823 known private
   `item_site` rows.
2. **Base URL** — assert no `localhost` and no S3 hostname appears in any generated file.
3. **Atomicity** — kill a run mid-write; assert the previous sitemap is intact and the index
   never references a missing child.
4. **Memory** — generate the full ~12k-entry set with media on and assert peak memory stays flat.
5. **Counts** — assert `countFor()` agrees with the number of entries `entriesFor()` yields.

## 17. Deferred past v1

- Versioned child filenames (decided against for now; see §8.2)
- Canonical-site selection to collapse cross-site duplication (goals Q2 option 2)
- Finer media scoping — per MIME type, per resource class, per site (§7)
- News/video-specific sitemap extensions beyond `image`/`video`
- A per-site admin page for the inclusion flags (storage already allows it, §12.1)
- Save-time staleness flagging

---

## 18. What implementation changed

Five things the design did not anticipate. All are now in the code; recorded here because
each was found by measurement rather than reasoning, and each would be easy to undo by
accident.

### 18.1 Doctrine's identity map defeated the streaming design

Every stage being a generator (§3) turned out not to be enough. The ORM retains every
entity the API hydrates, so memory grew **linearly** anyway — measured at **~4 MB per
1,000 items**, i.e. ~50 MB for this installation and unbounded for a larger one. The
streaming pipeline was real; the ORM was quietly holding everything behind it.

`AbstractSource::pageThrough()` now calls `$entityManager->clear()` after each page. Safe
because control only returns there once the consumer has finished with the previous
page — filters, enricher and writer have all run and the XML is on disk. It is also core's
own pattern: `Omeka\Job\IndexFulltextSearch` does the same for the same reason, and
Omeka's job dispatcher re-merges the job entity afterwards precisely because jobs are
expected to clear.

**Result:** flat. The identity map holds one page (~200 entities) constantly; a full run's
memory growth fell from 28 MB to 6 MB.

### 18.2 A stock install would have published `http:///files/...`

§4 said media URLs come from the file store and need no coupling to `AdvancedMedia`. True,
but incomplete: **Omeka's stock Local store derives its own base URI from `ServerUrl`**,
and in a job there is no request. Measured:

    ServerUrl()                  === 'http://'
    ServerUrl(BasePath('files')) === 'http:///files'

So the *generic* installation this module is written for — no proxying module involved at
all — would publish `http:///files/original/x.jpg` to every crawler. The `ProxyBaseUriCheck`
guard did not cover this, because it guards a *different* store whose own guard refuses;
the Local store has no such guard and emits the nonsense happily.

New class `Generator\PublicUrlContext` pins `ServerUrl` and `BasePath` to the configured
base URL for the run and restores them afterwards. Pinning rather than patching keeps us
out of another module's business: we set the context correctly and let every store derive
what it derives from a base URL that is now true.

Two sharp edges in that API, both load-bearing: `setPort()` casts to int, so `setPort(null)`
stores `0`, and `setHost()` reads the current scheme and port and appends `:0` unless the
port is null or the scheme default. Set them in the wrong order and you get
`https://host:0`.

### 18.3 `SiteSettings` is shared mutable state

`setTargetId()` leaves Omeka's `SiteSettings` service pointing at whatever site was looked
at last. Iterating sites therefore left it aimed at the final one, and anything reading
site settings afterwards — another module, a view helper, our own later code — silently
got that site instead of its own. This produced a genuinely confusing test failure before
it was understood.

Omeka's `get($id, $default, $targetId)` and `set($id, $value, $targetId)` set the target,
act, and restore it. **`setTargetId()` no longer appears anywhere in this module**, and a
regression test asserts the target is undisturbed after a full site iteration.

### 18.4 The media encoding was wrong in both directions

- **Images carried a `<image:title>` holding a URL.** Omeka's `displayTitle()` falls back
  to the media *source*, which for sideloaded items is the ingest URL. Google also
  deprecated every image extension field except `<image:loc>`. Images now emit `loc` only.
- **Videos were invalid.** `thumbnail_loc`, `title` and `description` are *required*; we
  emitted `content_loc` and an optional title. A video entry is now emitted complete or
  skipped entirely, and titles that are really filenames or URLs are rejected.
- **"Originals only" did not mean what it said.** It filtered on `hasOriginal()` while
  always publishing the original URL. It now selects *which file to advertise*: on, the
  original; off, the large derivative — which is usually what you want a crawler fetching,
  since an original here can be a 100 MB archival TIFF.

### 18.5 An empty run must not publish an empty sitemap

Not in the design at all. If nothing is enabled, replacing a good sitemap with a valid
empty one tells crawlers "there is no content here" — considerably worse than staying
silent. The pipeline now abandons staging, warns, and leaves any existing sitemap in place.

---

## 19. Verification performed

Harness scripts run in-container, bootstrapped via `\Omeka\Mvc\Application::init()`.

| area | result |
|---|---|
| Privacy — every published id checked against `is_public` | **pass**; 334 private items present in the source rows for the tested sites, all withheld; 3 private pages withheld |
| Counts — `countFor()` vs. entries actually yielded | **pass** for all sources; matches SQL independently (11,997 item URLs, 86 pages, 23 search pages) |
| Base URL — no `http:///`, no `localhost` in output | **pass** |
| XML well-formedness of every file | **pass** |
| Atomicity — crash mid-write | **pass**; previous sitemap byte-for-byte intact, staging removed |
| Atomicity — index promoted last | **pass**; a missing child aborts before the index moves |
| Index always emitted, even for one child | **pass** |
| Rollover at the entry limit | **pass** (4 files for 121 items at 40/file) |
| Orphan reaping across runs | **pass** |
| Exclusions — prefix match, counts, unused-rule reporting, comments | **pass** |
| `SiteSettings` target not leaked | **pass** |
| robots.txt — wildcard matching, `Allow` precedence, named-agent groups | **pass** |
| robots.txt — the "drop the `?`" tightening detected as a conflict | **pass** |
| robots.txt — append is idempotent and preserves content | **pass** |
| Memory flat at full scale | **pass** after §18.1 |
| CLI entry point through the real job dispatcher | **pass** |

Full run over `digital-collections` + `hrc`: **7,848 URLs, 8 files, 3.1 s.**

---

## 20. Layouts and the `/sitemap.xml` question (added after first deployment)

### 20.1 Omeka never claimed the path

A 404 at `/sitemap.xml` looked like a routing conflict and was not. Omeka's bundled
`.htaccess` serves any existing file before the request reaches `index.php`
(`RewriteCond %{REQUEST_FILENAME} -f`), so the router only ever sees that path **when no
file is there** — which is precisely the `files` layout, where the sitemap lives at
`/files/sitemaps/sitemap.xml`. Verified both ways: with a file present the response is a
static 200 carrying `ETag`/`Last-Modified` and no `X-Powered-By`; without one it is
`error-router-no-match`.

### 20.2 Why there is no route (D21)

A module route at `/sitemap.xml` has one elegant property — it is physically unreachable
whenever the static file exists, so it could never shadow the working path. That is not
enough to make it a good idea:

- **Serving through PHP defeats the premise.** Every fetch would boot the full Omeka MVC
  stack. The per-fetch cost is trivial in absolute terms, but it creates an
  unauthenticated endpoint that boots the application, in a module whose reason to exist
  is hostile bot traffic. A static file absorbs a hammering for free; that endpoint turns
  the same hammering into a database connection per request.
- **A 301 is cheaper but treats the symptom.** The underlying problem was clutter driving
  people away from the document root, and D19 addresses that directly.

A redirect remains a reasonable future option **for the `files` layout only**, where
`/sitemap.xml` genuinely cannot exist — opt-in and off by default.

### 20.3 Single-file mode (D20)

`Pipeline` streams every enabled (site, source) pair through **one** writer call instead of
one call per pair, so rollover only happens if the per-file limit forces it. If exactly one
file results it is renamed to `sitemap.xml` and published as a plain `<urlset>` with no
index; if more than one results, an index is written as usual **and a warning is recorded**.

The per-file limit is deliberately *not* overridden in this mode. It is a file-size safety
valve, not merely a splitting preference, and an administrator who capped it for their CDN
did not stop meaning it because they also asked for one file where possible.

D12's rationale survives intact: the published URL is `sitemap.xml` either way, so only the
document type at that URL changes when an installation outgrows a single file.

### 20.4 Published state is tracked separately (D22)

Changing layout strands the previous run's files at the old location — worse than clutter,
because a stale index goes on advertising URLs that no longer track the collection and
nothing in the application will ever correct it.

`AtomicPublisher::reapPreviousRun()` deletes **only the exact filenames the previous run
recorded writing, from the exact directories it recorded writing them to**. No pattern
guessing, so a hand-maintained `sitemap.xml` predating the module is never touched.

That requires knowing what is on disk, which is *not* the same question as what the last
run did — a failed run must record its failure without destroying the record of where the
working sitemap lives. So there are two settings: `staticsitemaps_last_run` (whatever
happened) and `staticsitemaps_published` (what is on disk, written only after a successful
publish).

Relatedly, the pipeline now persists its own report rather than leaving that to the job.
Cleanup on the next run depends on it having been recorded, so it cannot be left to
whichever entry point happened to invoke this one.

### 20.5 Verification

29 checks across the three layouts and both modes, all passing: correct index and child
placement per layout; document root reduced to exactly `sitemap.xml` + `sitemaps/` under
`docroot-subdir`; cross-layout cleanup leaving no strays; single mode producing one
`<urlset>` holding every URL; overflow falling back to an index with a warning and losing
no URLs; recovery back to a single file when the cap allows; and HTTP 200 static serving
for both `/sitemap.xml` and `/sitemaps/...`.
