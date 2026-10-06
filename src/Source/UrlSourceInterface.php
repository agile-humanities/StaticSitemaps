<?php
namespace StaticSitemaps\Source;

use Omeka\Api\Representation\SiteRepresentation;

/**
 * A contributor of URLs for one content type, for one site.
 *
 * Sources answer "what URLs exist?" and nothing else. They do not decide whether a
 * URL may be published (that is the filters' job, architecture §6) and they do not
 * decorate entries (that is the enrichers' job, §7).
 *
 * Adding a content type means adding a source to the registry and nothing else: the
 * configuration matrix is generated from the registry, so no UI work is required.
 */
interface UrlSourceInterface
{
    /** Stable id, used as the settings key and in child sitemap filenames. */
    public function getId(): string;

    /** Human label for the configuration matrix. */
    public function getLabel(): string;

    /**
     * Is this source usable in this installation?
     *
     * The hook for soft integrations (architecture §4): a source backed by an optional
     * module returns false when that module is absent, and the matrix hides its column
     * rather than offering a control that cannot work.
     */
    public function isAvailable(): bool;

    /**
     * How many URLs would this contribute for this site?
     *
     * MUST stay cheap. The configuration page calls this for every site and every
     * source on every load (R6), so it counts in the database and never builds URLs.
     */
    public function countFor(SiteRepresentation $site): int;

    /**
     * Yield UrlEntry objects for this site.
     *
     * MUST be a generator, and MUST page its database access. The whole pipeline is
     * lazy so that peak memory stays flat regardless of collection size
     * (architecture §3) — returning an array here would defeat every other stage.
     *
     * @return iterable<\StaticSitemaps\Generator\UrlEntry>
     */
    public function entriesFor(SiteRepresentation $site): iterable;
}
