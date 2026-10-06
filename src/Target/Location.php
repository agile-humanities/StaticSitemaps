<?php
namespace StaticSitemaps\Target;

/**
 * Resolves where sitemap files are written, and the local paths they are served at.
 *
 * ⚠️ These are LOCAL FILESYSTEM PATHS, deliberately not the Omeka file store.
 * On an S3-backed installation the store would put the sitemap in the bucket, where the
 * web server cannot serve it as a static file — the exact opposite of this module's
 * purpose. Reaching for the store abstraction is the natural mistake here.
 *
 * ── The three targets, and what each trades ─────────────────────────────────────
 *
 *   docroot          Everything flat at the document root. The index lands at the
 *                    conventional /sitemap.xml and every child sits beside it. Ideal
 *                    for a single-site installation, where that is two or three files.
 *                    On a multi-site install it is a lot of clutter — this project's
 *                    own instance produced 19 files in a document root of 48 entries.
 *
 *   docroot-subdir   Index at /sitemap.xml, children in /sitemaps/. The conventional
 *                    index URL with one file and one directory added to the document
 *                    root. The layout most large sites use.
 *
 *   files            Everything in files/sitemaps/. For installations where the
 *                    document root is not writable — commonly, because it is owned by
 *                    root. Note this puts the sitemap INSIDE the /files/ path space,
 *                    which on installations using AdvancedMedia's media proxy is
 *                    exactly the space bot-control rules are applied to; a carve-out
 *                    may be needed so the sitemap is not throttled along with media.
 *
 * ── The scope rule, which applies to all three ──────────────────────────────────
 * Under the sitemaps.org protocol a sitemap's location bounds the URLs it may contain:
 * strictly, a child in /sitemaps/ covers only URLs beneath /sitemaps/. Declaring the
 * sitemap in robots.txt is the recognised way around that, so only the flat `docroot`
 * layout is independent of robots.txt — and even there the directive is wanted for
 * discovery. This is why the module surfaces the Sitemap: line so prominently rather
 * than treating it as optional polish.
 */
class Location
{
    const TARGET_DOCROOT = 'docroot';
    const TARGET_DOCROOT_SUBDIR = 'docroot-subdir';
    const TARGET_FILES = 'files';

    /** Subdirectory name, used under the document root and inside files/. */
    const SUBDIR = 'sitemaps';

    /** @var string */
    private $target;

    /** @var string */
    private $omekaPath;

    public function __construct(string $target, string $omekaPath)
    {
        $this->target = array_key_exists($target, self::labels()) ? $target : self::TARGET_DOCROOT;
        $this->omekaPath = rtrim($omekaPath, '/');
    }

    public function target(): string
    {
        return $this->target;
    }

    /** Absolute directory the INDEX is written into. */
    public function indexDirectory(): string
    {
        return $this->target === self::TARGET_FILES
            ? $this->omekaPath . '/files/' . self::SUBDIR
            : $this->omekaPath;
    }

    /** Absolute directory CHILD sitemaps are written into. */
    public function childDirectory(): string
    {
        switch ($this->target) {
            case self::TARGET_FILES:
                return $this->omekaPath . '/files/' . self::SUBDIR;
            case self::TARGET_DOCROOT_SUBDIR:
                return $this->omekaPath . '/' . self::SUBDIR;
            default:
                return $this->omekaPath;
        }
    }

    /** Local path prefix the index is served at, no trailing slash. */
    public function indexPublicPrefix(): string
    {
        return $this->target === self::TARGET_FILES ? '/files/' . self::SUBDIR : '';
    }

    /** Local path prefix children are served at, no trailing slash. */
    public function childPublicPrefix(): string
    {
        switch ($this->target) {
            case self::TARGET_FILES:
                return '/files/' . self::SUBDIR;
            case self::TARGET_DOCROOT_SUBDIR:
                return '/' . self::SUBDIR;
            default:
                return '';
        }
    }

    /** Local path of the index, e.g. "/sitemap.xml". */
    public function indexPath(string $indexFile): string
    {
        return $this->indexPublicPrefix() . '/' . $indexFile;
    }

    /** Does this target need a directory created that may not exist yet? */
    public function needsChildDirectory(): bool
    {
        return $this->childDirectory() !== $this->omekaPath;
    }

    /** True when the index and children share a directory. */
    public function isFlat(): bool
    {
        return $this->indexDirectory() === $this->childDirectory();
    }

    public static function labels(): array
    {
        return [
            self::TARGET_DOCROOT => 'Document root — everything at the top level', // @translate
            self::TARGET_DOCROOT_SUBDIR => 'Document root, children in /sitemaps/', // @translate
            self::TARGET_FILES => 'Files directory (files/sitemaps/)', // @translate
        ];
    }

    public static function descriptions(): array
    {
        return [
            self::TARGET_DOCROOT =>
                'The index and every child sit at the document root. Simplest, and the only layout '
                . 'whose files are all in scope without relying on robots.txt. On a site with many '
                . 'subsites this can mean a lot of files.', // @translate
            self::TARGET_DOCROOT_SUBDIR =>
                'The index stays at the conventional /sitemap.xml; children go in /sitemaps/. Adds '
                . 'one file and one directory to the document root.', // @translate
            self::TARGET_FILES =>
                'For document roots that are not writable. Nothing is added to the document root, '
                . 'but /sitemap.xml will not exist, and the sitemap sits inside the /files/ path '
                . 'space that media bot-control rules usually target.', // @translate
        ];
    }
}
