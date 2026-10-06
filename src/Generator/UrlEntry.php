<?php
namespace StaticSitemaps\Generator;

/**
 * One <url> element, in the abstract.
 *
 * Entries carry a LOCAL PATH, never an absolute URL. Absolutisation happens once,
 * in the writer, through UrlBuilder (architecture §8.1, §10). That is deliberate:
 *
 *   - exclusion matching stays simple, because rules are written against the same
 *     local paths an administrator sees in a browser address bar;
 *   - sources stay ignorant of deployment, so nothing has to be re-plumbed when the
 *     base URL changes;
 *   - there is exactly one place where a wrong host could be introduced.
 *
 * Media URLs are the one exception: they arrive already absolute from the file
 * store (which is what lets AdvancedMedia's proxying override work without this
 * module knowing about it), so they are kept verbatim.
 */
class UrlEntry
{
    /** @var string Local path with a leading slash, e.g. "/s/monsters/item/4403". */
    private $path;

    /** @var \DateTimeInterface|null Real modification time, or null if there is none. */
    private $lastmod;

    /** @var array<int, array{loc: string}> Absolute image URLs. */
    private $images = [];

    /** @var array<int, array{loc: string, title: string, thumbnail: string}> */
    private $videos = [];

    /**
     * @var int|null The resource id this entry came from, when it came from one.
     *               Used by the privacy filter for its second check, and by nothing
     *               that writes XML.
     */
    private $resourceId;

    /**
     * @var object|null The representation this entry came from, when it came from one.
     *
     * Carried so the media enricher can reach an item's media and the privacy filter
     * can re-check it, WITHOUT re-fetching by id — a re-fetch per entry would undo the
     * paging the source just did and turn a flat-memory stream into N+1 queries.
     * Nothing that writes XML touches this.
     */
    private $representation;

    public function __construct(string $path, ?\DateTimeInterface $lastmod = null, ?int $resourceId = null)
    {
        $this->path = $path;
        $this->lastmod = $lastmod;
        $this->resourceId = $resourceId;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function lastmod(): ?\DateTimeInterface
    {
        return $this->lastmod;
    }

    public function resourceId(): ?int
    {
        return $this->resourceId;
    }

    public function setRepresentation($representation): void
    {
        $this->representation = $representation;
    }

    public function representation()
    {
        return $this->representation;
    }

    public function addImage(string $loc): void
    {
        $this->images[] = ['loc' => $loc];
    }

    /** Title and thumbnail are required by the video sitemap spec, so both are required here. */
    public function addVideo(string $loc, string $title, string $thumbnail): void
    {
        $this->videos[] = ['loc' => $loc, 'title' => $title, 'thumbnail' => $thumbnail];
    }

    public function images(): array
    {
        return $this->images;
    }

    public function videos(): array
    {
        return $this->videos;
    }

    public function hasMedia(): bool
    {
        return $this->images || $this->videos;
    }
}
