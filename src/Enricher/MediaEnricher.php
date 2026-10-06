<?php
namespace StaticSitemaps\Enricher;

use StaticSitemaps\Generator\UrlEntry;

/**
 * Decorates item entries with media, as sitemap image/video EXTENSIONS (D9).
 *
 * Media is an enricher, not a source, and that is the whole reason it is a global
 * switch rather than a per-site one (D18): it changes how item entries are written,
 * not which sites contribute entries. Nested <image:image>/<video:video> elements are
 * the form search engines document for media discovery, they associate each file with
 * the page that presents it, and they keep the URL count at item level — so turning
 * media on cannot, by construction, multiply the sitemap into an index of 85,000
 * standalone file URLs.
 *
 * ── The AdvancedMedia integration is the absence of one ──────────────────────────
 * URLs come from MediaRepresentation::originalUrl(), which resolves through the file
 * store's getUri(). That is exactly the method AdvancedMedia's proxying store
 * overrides. So where proxying is on we emit rewritten same-origin URLs, and on a
 * stock install we emit ordinary store URLs — with no class_exists check, no adapter,
 * and no knowledge of that module anywhere in this file. The correct URL is whatever
 * the store says it is.
 *
 * The matching hazard is checked before a run starts, not here: AdvancedMedia's store
 * self-disables when it cannot find a base URI, and jobs have no request, so a run
 * with a blank advancedmedia_files_base_uri would silently publish S3 URLs. See
 * StaticSitemaps\Target\ProxyBaseUriCheck.
 */
class MediaEnricher
{
    /** @var bool */
    private $primaryOnly;

    /** @var bool */
    private $originalsOnly;

    /** @var int */
    private $imageCount = 0;

    /** @var int */
    private $videoCount = 0;

    public function __construct(bool $primaryOnly = true, bool $originalsOnly = true)
    {
        $this->primaryOnly = $primaryOnly;
        $this->originalsOnly = $originalsOnly;
    }

    /**
     * @param iterable<UrlEntry> $entries
     * @return iterable<UrlEntry>
     */
    public function __invoke(iterable $entries): iterable
    {
        foreach ($entries as $entry) {
            $this->enrich($entry);
            yield $entry;
        }
    }

    private function enrich(UrlEntry $entry): void
    {
        $item = $entry->representation();
        if (!$item || !method_exists($item, 'media')) {
            return;
        }

        foreach ($this->mediaOf($item) as $media) {
            if (!$this->isUsable($media)) {
                continue;
            }

            $type = (string) $media->mediaType();
            $url = $this->urlFor($media);
            if (!$url) {
                continue;
            }

            if (strpos($type, 'image/') === 0) {
                // <image:loc> ONLY. Google deprecated the other image extension fields
                // (title, caption, licence, geo_location) and ignores them — and
                // Omeka's displayTitle() falls back to the media SOURCE, which for
                // sideloaded items is frequently the ingest URL. Emitting that put a
                // URL where a human-readable title belongs.
                $entry->addImage($url);
                $this->imageCount++;
            } elseif (strpos($type, 'video/') === 0) {
                // Video is the opposite case: thumbnail_loc, title and description are
                // REQUIRED by the spec, so an entry carrying only content_loc is
                // invalid. Emit a complete one or none at all.
                $thumbnail = $media->thumbnailUrl('large') ?: $media->thumbnailUrl('medium');
                $title = $this->humanTitle($media, $item);
                if (!$thumbnail || $title === null) {
                    continue;
                }
                $entry->addVideo($url, $title, $thumbnail);
                $this->videoCount++;
            }
            // Everything else is skipped on purpose. The sitemap protocol has no
            // element for a PDF, an audio file or a VTT sidecar, and inventing one is
            // worse than omitting it.
        }
    }

    /**
     * Which file to advertise.
     *
     * "Originals only" means what it says: publish the original file. Switched off, we
     * publish the large derivative instead, which is usually what an installation
     * actually wants a crawler to fetch — an original here can be a 100 MB archival
     * TIFF, and pointing crawlers at thousands of those is neither useful to them nor
     * kind to the server.
     */
    private function urlFor($media): ?string
    {
        if (!$this->originalsOnly) {
            $derivative = $media->thumbnailUrl('large');
            if ($derivative) {
                return $derivative;
            }
        }

        return $media->originalUrl() ?: null;
    }

    /**
     * A title fit for publication, or null when all we have is a filename or a URL.
     *
     * displayTitle() falls back to the media source, so it can hand back an ingest URL
     * or a bare filename. Neither is a title, and filling a REQUIRED field with a URL
     * is worse than skipping the entry.
     */
    private function humanTitle($media, $item): ?string
    {
        foreach ([$media->displayTitle(''), $item->displayTitle('')] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL)) {
                continue;
            }
            if ((string) $media->source() === $candidate) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function mediaOf($item): array
    {
        if ($this->primaryOnly) {
            $primary = $item->primaryMedia();

            return $primary ? [$primary] : [];
        }

        return $item->media();
    }

    private function isUsable($media): bool
    {
        // hasOriginal() also drops the zero-byte x-empty records known to exist in
        // real collections, which would otherwise be advertised as fetchable files.
        // Checked regardless of the originals-only setting: a media with no stored
        // file has nothing to advertise either way.
        if (!$media->hasOriginal()) {
            return false;
        }
        if (!$media->isPublic()) {
            return false;
        }

        return true;
    }

    public function imageCount(): int
    {
        return $this->imageCount;
    }

    public function videoCount(): int
    {
        return $this->videoCount;
    }
}
