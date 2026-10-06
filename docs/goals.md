# Static Sitemaps — Project Goals

**Status:** planning. Nothing built yet.
**Working module name:** `StaticSitemaps` (see Q13).
**Author context:** Pitts Theology Library / pitts-2026, Omeka S 4.2.
**Intended audience:** general Omeka S community — see §4.
**Sibling work:** [`AdvancedMedia`](../../AdvancedMedia/docs/) — media URL proxying, `docs/media-routing.md`.

---

## 1. Purpose

This module is the second component of a broader effort to **shape bot traffic and spare
server resources**.

The first component, delivered in `AdvancedMedia`, created a **pinch point for Omeka's media
URLs**: every media URL is rewritten to a same-origin `/files/{storagePath}` path on this host,
so that a single structured path space — rather than scattered S3 bucket URLs — can be governed
by network-level rules (rate limits, UA filtering, WAF).

This module supplies the other half of that strategy. A pinch point tells you where to *apply*
policy; it does not tell a crawler where the content *is*. The sitemap is the positive signal
that pairs with the negative ones:

> **Legitimate crawlers get an explicit, cheap, complete map of the content we want indexed —
> so that the CDN and WAF are free to aggressively restrict everything else, especially the
> faceted discovery UIs.**

The faceted discovery interfaces provided by the Advanced Search module are the specific
pressure point. They are combinatorially large, expensive to render, and a standing target for
malicious and poorly-behaved bots. Today a crawler that wants our content has little choice but
to walk those UIs. Once a sitemap exists, it does not need to — and blocking facet traffic stops
being a trade-off against discoverability.

## 2. Why a static sitemap, specifically

A dynamically generated sitemap would defeat the purpose. Every crawler request for it would be
a full PHP render over the whole collection — the module would itself become the resource strain
it was built to relieve, and would be an attractive target for exactly the bots we are managing.

We investigated the existing third-party option and confirmed this concern:

> **`omeka-s-module-Sitemaps`** (Laurent Thomas / ManOnDaMoon,
> <https://github.com/ManOnDaMoon/omeka-s-module-Sitemaps>) generates on the fly. Its own
> documentation states: *"The `sitemap.xml` file is dynamically generated: there is no file
> physically created on the server."* It exposes `/s/{site-slug}/sitemap.xml` and
> `/s/{site-slug}/sitemap-index.xml`, offers two per-site settings and states *"There is no
> module specific configuration"*, and documents no caching.

Not a criticism of that module — it is a reasonable design for a small installation. It is the
wrong design for ours (~92,000 resources across 17 sites) and the wrong design for this project's
goal. **We should still read its source as a reference** for sitemap-index pagination, XML
generation, and per-site enablement.

By contrast, a file written to disk is served by Apache directly: the stock `.htaccess` serves
any path that exists on disk *before* the request reaches `index.php`. A crawler fetching our
sitemap costs us a static file read and **zero PHP**.

## 3. Goals

1. **Discoverability.** Every public URL we want indexed appears in a sitemap, so no crawler
   needs to walk a faceted UI to find content.
2. **Zero marginal cost per crawl.** Serving the sitemap consumes no PHP and no database.
3. **Enable aggressive restriction elsewhere.** The sitemap is what makes it safe to block or
   throttle discovery paths at the CDN/WAF layer.
4. **Administrator control.** What gets indexed is a decision made in the admin UI, per site and
   globally — not a hard-coded policy.
5. **Honest feedback.** The module tells the administrator whether the sitemap is actually
   discoverable, rather than silently producing something crawlers will never see.
6. **Community contribution.** This is built to be released as a general-purpose Omeka S module,
   not a Pitts-specific tool. See §4.

## 4. Built for general release

**The module must be written as generically as possible so it can be contributed back to the
community.** This is a design constraint with concrete consequences, not a statement of intent:

- **No hard dependency on `AdvancedMedia`.** Most installations use generic media paths — a
  Local file store where `/files/original/...` is already a real static file served by the web
  server. That is the baseline the module must work against. Where `AdvancedMedia` *is* present
  and proxying is on, the module must use its rewritten URLs. The integration is detected at
  runtime and degrades cleanly when the module is absent.
- **No hard dependency on `AdvancedSearch`.** The search-page requirement (R3.3) is an optional
  integration on the same terms: present it when the module is installed, omit the control
  entirely when it is not. Nothing about the core sitemap may break without it.
- **Conservative defaults.** Anything expensive, opinionated, or installation-specific ships
  **off**. An administrator who installs the module and clicks Generate should get a small,
  safe, obviously-correct sitemap — not a surprise.
- **Works with either file store**, Local or S3, and with either Apache or nginx in front.
- **Pitts-specific policy lives in configuration, not in code.**

## 5. Non-goals

- Not a general SEO suite. No meta tags, structured data, or `rel=canonical` management
  (though Q2 raises canonicalisation as an adjacent concern for the theme).
- Not a replacement for CDN/WAF rules. The sitemap is the allow-signal; the blocking lives in
  Cloudflare/CloudFront and nginx.
- Not real-time. Sitemaps are point-in-time snapshots regenerated on a schedule; some staleness
  is accepted by design (see Q12).
- Not a crawler analytics or log-analysis tool.

## 6. Requirements

Numbered for reference in later design docs. **(D)** marks a resolved decision; see §8.

### R1 — Static output
Generate sitemap XML as **files on disk**, served by the web server without invoking Omeka.

### R2 — Regeneration as an Omeka job
Regeneration runs as a proper Omeka background job, dispatchable two ways:
- **R2.1** on administrator request (a button in the admin UI)
- **R2.2** on a schedule, via cron

### R3 — Configuration
- **R3.1 Global configuration page** — module-level settings.
- **R3.2 Per-site inclusion control** — each site decides what it contributes.
- **R3.3 Per-site content types (D).** Each site opts in independently, per type. The UI is a
  checkbox matrix, one row group per site:

      Paradise Lost (paradise-lost)
        [ ] Pages
        [ ] Items
        [ ] Item sets
        [ ] Advanced Search pages

  **Media is deliberately absent from this matrix — it is a global option only (D18).**

  - **Redundancy is a feature, not a bug.** A "subsite" is frequently meant to stand alone even
    though it shares items with a parent site. Administrators opt in to duplication deliberately;
    the module must not deduplicate across sites on their behalf. (Closes Q2.)
  - **Item sets** ship OFF by default. At Pitts they are not the preferred path to items; in a
    more generic Omeka build they often are, so the capability belongs in the module while the
    default stays conservative.
  - **Advanced Search pages:** include **all search configs targeted at that site**. Advanced
    Search already scopes its configs per site, so that assignment is the administrator's
    existing statement of intent; per-page opt-out is served by the exclusion list (R3.6).
    *Optional integration — the control is hidden when the module is absent (§4).*
  - Each checkbox displays the **URL count it will contribute** before saving (R6).
- **R3.4 Media entries — GLOBAL ONLY, OFF by default (D).** Media inclusion is not a per-site
  choice: one switch governs the whole installation.
  - **Encoding: image/video sitemap extensions** (`<image:image>`, `<video:video>`) nested inside
    the parent item's `<url>` entry — not standalone `<loc>` entries. This is the form search
    engines document for media discovery, it keeps the URL count at item level, and it avoids
    forcing an index purely on media volume.
  - **Scope options, both defaulting ON:**
    - *Primary media only* — one representative media per item.
    - *Originals only* — no derivatives.
  - **Noted for the future:** this scoping is deliberately coarse. Real installations will want
    finer control (per MIME type, per resource class, per site). The v1 shape should not
    foreclose that.
  - Against a generic Omeka install media are ordinary static file paths, which is cheap and
    correct — hence the option exists for the community build.
  - Where `AdvancedMedia` proxying is active, emitted URLs **must** be the rewritten/proxied
    URLs, never raw S3 bucket URLs.
- **R3.5 Global "everything" overrides** — global options to include *all items* and *all site
  pages across all sites*. **Off by default.** When on, they supersede and disable the
  corresponding per-site controls (the UI must show this clearly, not just ignore the per-site
  settings).
- **R3.6 Path exclusions** — a plain textarea of local routes to exclude. Exclusions may name
  paths from any site and **override all other settings**.
- **R3.7 Output location** — build into the **docroot (default)** or the **files directory**.
  - **R3.7.1 Writability preflight (D).** Before offering or committing to a location, probe
    that it is actually writable and report the result in the UI. The docroot is commonly
    root-owned on real servers. Refuse with a plain-language explanation rather than failing at
    job time — mirroring `AdvancedMedia`'s `ReachabilityProbe` pattern.
- **R3.8 robots.txt integration**
  - **R3.8.1 Show the literal line (D).** Display the exact `Sitemap:` directive for the
    administrator's chosen output path, ready to copy, so it can be added by hand. This is the
    baseline behaviour and always available — `robots.txt` is frequently version-controlled
    (it is here), read-only, or managed outside the application, so writing to it can never be
    the only path.
  - **R3.8.2 Optional automatic append** — offer to write the directive into `robots.txt`
    directly, subject to the same writability preflight.
- **R3.9 robots.txt audit** — inspect `robots.txt` and report back whether the generated sitemap
  is actually discoverable, and whether anything listed in it is blocked from crawling.

### R4 — Configurable public base URL (D)
The public base URL used to build absolute `<loc>` values is an explicit configuration setting.
Background jobs have no HTTP request, so `ServerUrl` and route helpers have nothing to
auto-detect from — this has already produced wrong stored URLs twice in this installation (the
IIIF manifest cache rebuild and the Solr reindex). **Generation must refuse to run when no base
URL is configured** rather than emit a sitemap full of `http://localhost/...`.

### R5 — Never publish anything private (D)
No private resource may ever appear in a sitemap. This applies at every level: private **sites**,
private **site pages**, private **items**, private **item sets**, and private **media**.

**This is not automatic, and the obvious implementation gets it wrong.** Core's
`Omeka\Job\UpdateSiteItems` populates `item_site` with `view-all` privileges explicitly granted
(its own comment: *"Grant 'view-all' privileges to include private items"*), so **`item_site`
contains private items** — 823 of the 12,820 rows on public sites here reference one. A
generation job run under an administrator's account would, through the API, be equally entitled
to see them. Public-only filtering must therefore be **explicit and deliberate at every query**,
never inherited from the caller's privileges.

**R5.1** — the module must **surface what it excluded**, per site and per type, alongside the
inclusion counts. Silent omission is as unhelpful as silent inclusion; an administrator should be
able to see "3 private pages excluded" and know the number is intentional.

### R6 — Show the counts before saving (D)
Every inclusion control displays the number of URLs it will contribute, computed against real
data, before the administrator commits. This is what makes verbatim listing (D7) safe: the module
never overrules the administrator about their own site's contents, but it never lets them enable
something blind either. Same principle as the robots.txt audit (R3.9).

### R7 — Edge caching and serving headers (D)
The module emits `Cache-Control` headers guidance and documentation for the layers in front of
it. **Expiry is the mechanism; purging is the escape hatch, not the requirement.**

- **R7.1 Bound staleness correctly.** Worst-case staleness is `regeneration interval + edge TTL`,
  not the larger of the two. A 24h TTL on a nightly rebuild yields up to ~48h of stale sitemap. A
  **short TTL (≈1h) is the right default**: sitemap fetches are rare and the files are small, so
  a short TTL costs essentially nothing at the origin while cutting worst-case staleness to ~25h.
- **R7.2 Make revalidation cheap.** Serve `ETag`/`Last-Modified` (nginx and Apache do this for
  static files automatically) so an expired edge copy costs a conditional `GET` and a `304`, not
  a re-transfer. This is what makes a short TTL free.
- **R7.3 Documentation covers Cloudflare, CloudFront and nginx.** All three, since the module is
  for general release and installations will differ.
  - **Cloudflare:** the failure mode is the *opposite* of staleness — `.xml` is not among the
    file extensions Cloudflare caches by default, so sitemaps are likely **not edge-cached at
    all** until a Cache Rule is added for the sitemap paths. Purge-by-URL is available and
    unmetered if immediate propagation is ever wanted.
  - **CloudFront:** honours origin `Cache-Control` when the cache policy is configured to (the
    managed caching-optimised policy does), bounded by the behaviour's min/max TTL. Invalidations
    are billed beyond a monthly free allowance, which is the specific reason **not** to make
    "purge everything after every rebuild" the recommended pattern.
  - **nginx:** a snippet in the spirit of the one `AdvancedMedia` ships — a `location` match for
    the sitemap paths setting `Cache-Control`, `gzip_static on;` if pre-compressed files are
    written, and correct `application/xml` typing.
  - **Apache note for this stack:** the container has `mod_headers` and `mod_deflate` but **not
    `mod_expires`**, so guidance must use `Header set Cache-Control`, not `ExpiresByType`.
- **R7.4 Purge is optional and administrator-driven.** The one case that genuinely wants it is
  R5-adjacent: an administrator unpublishes something sensitive and wants the sitemap entry gone
  from the edge now rather than at expiry. Document how; do not require it.
- **R7.5 Traffic-shaping rules are the administrator's business (D).** The module documents the
  caching integration it owns and the consistency principle (never advertise a URL the WAF
  blocks). It does not ship or prescribe bot-management, rate-limiting or blocking rules.
- **R7.6 Optional pre-compression.** The sitemap protocol permits gzipped sitemaps, and sitemap
  XML compresses roughly 10:1. Writing `.xml.gz` alongside each file lets nginx serve it with
  `gzip_static` at zero CPU. Worth considering; not required for v1.

## 7. What the current installation looks like

Measured against the live database (`pittsviv_os1`) on 2026-09-08. These numbers set the scale
the design has to survive. They are Pitts figures, not assumptions about other installations.

| | count |
|---|---|
| Sites | 17 — **9 public**, 8 private ("Take 2" duplicates) |
| Site pages | 171 (~84 on public sites) |
| Items | 5,886 |
| Item sets | 383 |
| Media | 85,800 |
| Public resources | 91,652 of 92,069 |

**Site membership is materialised, not computed at query time.** Omeka's `ItemAdapter` resolves
a `site_id` query by joining the **`item_site`** table — it does *not* re-evaluate the site's
`item_pool` JSON. The pool is the rule that populates that table; the table is what the site
actually shows. Enumerating a site's items therefore means an indexed join, which is cheap, and
means we read exactly what visitors see.

**Public items per public site** (from `item_site`, public items only):

| site | items |
|---|---|
| `digital-collections` | 5,708 |
| `paradise-lost` | 3,589 |
| `hrc` | 2,074 |
| `masquerade` | 1,246 |
| `monsters` | 127 |
| `hidden-legacies` | 28 |
| `workshops` | 27 |
| `reformation-counterfeits` | 21 |

**5,504 distinct public items are in at least one public site, but listing every site's items
would emit 11,997 URLs — a 2.18× duplication multiplier.** Only **172 items** appear in exactly
one public site; **4,208** appear in two, **1,087** in three, and **37** in four. Cross-site
duplication is the normal case here, not an edge case.

The four sites with an empty `item_pool` (`paradise-lost`, `monsters`, `masquerade`,
`reformation-counterfeits`) illustrate the risk: an empty pool matches *everything*, and
`paradise-lost` has accordingly accumulated 3,589 item memberships and `masquerade` 1,246 —
broad snapshots on sites that are conceptually small exhibits.

**Private content that must be filtered out** (R5). Note the first row especially:

| | count |
|---|---|
| `item_site` rows on public sites referencing a **private item** | **823** of 12,820 |
| Private site pages on public sites | 3 (of 89) |
| Private item sets | 35 (of 383) |
| Private media | 0 |
| Private sites (excluded entirely) | 8 (of 17) |

Core's `UpdateSiteItems` job grants itself `view-all` when it populates `item_site`, so that
table is *not* a public-only view. The 823 rows are the concrete evidence that public-only
filtering has to be explicit.

**An empty item pool genuinely means "all items."** Confirmed in core: `UpdateSiteItems` runs
`$api->search('items', $query)` with the pool as `$query`, and its own comment reads *"If the
query is not an array, assume an all-inclusive query."* So an empty pool is not a
misconfiguration to be corrected — it is a valid, and for single-site installations a completely
normal, statement that the site holds everything.

**Advanced Search** defines 7 search configs, each with a slug that becomes a site path:
`discover`, `advanced_search_2`, `hrc-search`, `hidden-legacies`, `workshop-search`, `search`,
`sermon-search`. Sites select among them via the `advancedsearch_configs` site setting.

**Existing `robots.txt`** (docroot, and **tracked in git**) is two lines:

    User-agent: *
    Disallow: /s/*/discover?*

Note that this blocks the *query-string* facet permutations while leaving the bare landing page
crawlable. That is already the posture this project wants, and it means listing Advanced Search
landing pages in the sitemap is consistent with the existing rules.

**Other mechanics that matter:**

- `.htaccess` serves any on-disk file before routing to `index.php` — the static-serving win is
  already wired, no server change needed for the docroot option.
- `resource.modified` is fully populated, no NULLs — a real `<lastmod>` is free.
- URL budget: ~12k item URLs (all public sites) + ~84 pages + up to 383 item sets sits under the
  50,000-URL / 50 MB per-file limit. Including media (85,800) crosses it and forces an index.
- No `rel=canonical` tag exists anywhere in the themes today — nothing currently mitigates the
  duplication above.
- PHP CLI is present (`/usr/local/bin/php`, 8.3.20), so Omeka's `PhpCli` job dispatch strategy
  works. There is **no crontab in the application container** — scheduling would come from the
  host or the deployment environment.
- The docroot is a git checkout; `/files` is gitignored. Locally the docroot is writable only
  because of the Docker bind mount; on a real server it is typically root-owned.

## 8. Decisions made

Recorded 2026-09-08.

| # | Decision |
|---|---|
| D1 | **Build for general release.** Generic by default; `AdvancedMedia` and `AdvancedSearch` are soft, runtime-detected integrations. (§4, closes Q15) |
| D2 | **Media paths: keep the option, default off.** Most builds use generic static media paths where this is cheap and correct. (R3.4, partially closes Q3) |
| D3 | **Base URL is configurable and required.** No auto-detection; refuse to generate without it. (R4, closes Q8) |
| D4 | **Writability preflight** for the chosen output location. (R3.7.1, closes Q6) |
| D5 | **Always show the literal `Sitemap:` line** for the chosen path so administrators can add it by hand; automatic append is an additional option, never the only path. (R3.8.1, closes Q7) |
| D6 | **Item sets are included as a content type, off by default.** (R3.3, closes Q5) |

Recorded 2026-09-08 (round 2).

| # | Decision |
|---|---|
| D7 | **List site membership verbatim and surface the count.** The module never second-guesses what a site contains; it makes the consequence visible before the administrator commits. An empty item pool is not special-cased. (R6, closes Q1) |
| D8 | **Per-site opt-in per content type; cross-site redundancy is intentional.** No deduplication across sites. (R3.3, closes Q2) |
| D9 | **Media use image/video sitemap extensions**, scoped by *primary media only* and *originals only*, both defaulting on; finer granularity noted as future work. (R3.4, closes Q3) |
| D10 | **List all Advanced Search configs targeted at a site**; the exclusion list is the opt-out mechanism. (R3.3, closes Q4) |
| D11 | **Exclusions: prefix match on the local path**, case-insensitive, trailing slashes normalised, per-rule removal count reported. Documentation must state plainly that this **guides** crawlers rather than **controls** them. (R3.6, closes Q9) |
| D12 | **Always emit a sitemap index**, even with a single child file. (closes Q10) |
| D13 | **`<lastmod>` only.** No `<changefreq>` (Omeka content rarely changes on a schedule) and no `<priority>` (not meaningfully assessable). (closes Q11) |
| D14 | **Nightly regeneration as the documented default**, interval administrator-configurable, "last generated" surfaced in the UI. (closes Q12) |
| D15 | **Module name is `StaticSitemaps`.** (closes Q13) |
| D16 | **Nothing private is ever published, and exclusions are surfaced.** (R5, R5.1) |
| D17 | **Document Cloudflare, CloudFront and nginx**, plus the caching integration the module owns. Other network traffic-shaping rules are left to the administrator. (R7, closes Q14) |
| D18 | **Media inclusion is a global option only**, not per-site. (R3.4, closes Q17) |

Recorded 2026-09-08 (round 3, after first deployment).

| # | Decision |
|---|---|
| D19 | **Three output layouts, not two:** document root (flat), document root with children in `/sitemaps/`, and files directory. The middle option keeps the conventional `/sitemap.xml` while adding one file and one directory to the document root — this installation's 17 sites produced 19 files in a document root of 48 entries, which is not acceptable, while a single-site install is two or three files and is fine flat. (R3.7) |
| D20 | **A "single sitemap file" option, default off.** Writes one `<urlset>` instead of an index plus children. A preference, not a guarantee: the per-file limit still governs, and a collection that outgrows it is split into an index automatically **with a warning**, because the layout the administrator asked for is not the one they got. The published URL is `sitemap.xml` in both cases, so D12's stable-URL rationale is preserved — only the document type at that URL changes. |
| D21 | **No route or controller for `/sitemap.xml`.** Omeka never claimed that path: the bundled `.htaccess` serves any real file before routing, so the router only sees it when no file is there. Serving the sitemap through PHP would put the full MVC bootstrap in the crawl path and create an unauthenticated app-booting endpoint in a project explicitly about hostile bot traffic. The layouts in D19 solve the underlying problem instead. |
| D22 | **Track published state separately from the run report.** A failed run must record that it failed without destroying the record of where the working sitemap actually lives — otherwise the next successful run cannot clean up after a layout change. |

## 9. Remaining unknowns

No open questions block design. Architecture: [`architecture.md`](architecture.md).

- **Verify vendor specifics before publishing documentation.** Cloudflare's default cached-extension
  list, CloudFront's cache-policy behaviour and invalidation allowances, and both platforms' bot
  verification features move over time. Every claim in R7.3 should be checked against current
  vendor documentation at the time we write the README, not taken from this document.
- **Versioned child sitemaps — decided against for v1, left open for the future.** Child filenames
  are stable. Versioning them (`sitemap-items-1.20260908.xml`) would let children be cached
  indefinitely and remove any need to purge, but it trades a purge problem for a retention-and-churn
  problem. Revisit if edge caching proves awkward in practice.
- **Item set to site scoping — confirmed.** `ItemSetAdapter` resolves a `site_id` query by joining
  `siteItemSets` (`site_item_set`), a different table from the `item_site` join `ItemAdapter` uses.
  Passing `site_id` to the API inherits the correct behaviour for both.
- **Redirect sources.** Some sites set `advancedsearch_redirect_itemset = "browse"`, so certain
  item set URLs 302 elsewhere. A sitemap of redirects is a crawl-quality problem; needs a
  concrete rule once item sets are exercised.
- **Site page `lastmod` fidelity.** `site_page.modified` reflects the page record, not the items
  displayed on it, so a listing page whose contents changed shows a stale `lastmod`. Probably
  acceptable — worth stating in the README rather than discovering later.
