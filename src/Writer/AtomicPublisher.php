<?php
namespace StaticSitemaps\Writer;

use StaticSitemaps\Target\Location;

/**
 * Publishes a generated set of files atomically.
 *
 * Two guarantees, and they matter more here than in most write paths BECAUSE the output
 * is static: these files are served by the web server with no application in the request
 * path, so there is nothing to catch an error at request time. A half-written sitemap is
 * simply a broken sitemap, served to crawlers and then edge-cached.
 *
 *   1. A crawler never sees a partially written file.
 *   2. A failed run never destroys a good previous result.
 *
 * The mechanism: generate everything into a staging directory, then rename() each file
 * into place — atomic within a filesystem, which is why staging sits BESIDE the target
 * rather than in a system temp directory (/tmp is frequently a different filesystem,
 * where rename() silently degrades to a non-atomic copy).
 *
 * The index is renamed LAST, so it can never reference a child that is not yet in place.
 * Orphans are removed only after that, so a crawler holding an older index still finds
 * its children throughout.
 */
class AtomicPublisher
{
    const STAGING_DIR = '.staticsitemaps-staging';

    /** @var Location */
    private $location;

    /** @var string */
    private $stagingDir;

    public function __construct(Location $location)
    {
        $this->location = $location;
        // Staged inside the index directory. Both target directories are always on the
        // same filesystem (one is the other, or its child), so rename() stays atomic.
        $this->stagingDir = $location->indexDirectory() . '/' . self::STAGING_DIR;
    }

    public function beginStaging(): string
    {
        if (is_dir($this->stagingDir)) {
            $this->removeDirectory($this->stagingDir);
        }
        if (!@mkdir($this->stagingDir, 0775, true) && !is_dir($this->stagingDir)) {
            throw new \RuntimeException(sprintf(
                'Could not create the staging directory "%s".',
                $this->stagingDir
            ));
        }

        return $this->stagingDir;
    }

    /**
     * Move the generated set into place and reap what it superseded.
     *
     * @param string[] $childFiles Child sitemap filenames (may be empty in single-file mode).
     * @param string   $indexFile  The file published as the sitemap entry point, renamed last.
     * @param array    $previous   The previous run's report, used to clean up precisely.
     * @return string[] Files removed.
     */
    public function publish(array $childFiles, string $indexFile, array $previous = []): array
    {
        $childDir = $this->location->childDirectory();
        if ($childFiles && $this->location->needsChildDirectory() && !is_dir($childDir)) {
            if (!@mkdir($childDir, 0775, true) && !is_dir($childDir)) {
                throw new \RuntimeException(sprintf('Could not create "%s".', $childDir));
            }
        }

        foreach ($childFiles as $file) {
            $this->promote($file, $childDir);
        }
        // Last, so the index never points at a child that is not yet in place.
        $this->promote($indexFile, $this->location->indexDirectory());

        $removed = $this->reapOrphans($childFiles, $childDir);
        $removed = array_merge($removed, $this->reapPreviousRun($previous, $childFiles, $indexFile));

        @rmdir($this->stagingDir);

        return $removed;
    }

    /**
     * Remove the staging directory without publishing. Called when a run fails, so the
     * previous sitemap survives untouched.
     */
    public function abandonStaging(): void
    {
        if (is_dir($this->stagingDir)) {
            $this->removeDirectory($this->stagingDir);
        }
    }

    private function promote(string $filename, string $targetDir): void
    {
        $from = $this->stagingDir . '/' . $filename;
        $to = rtrim($targetDir, '/') . '/' . $filename;

        if (!is_file($from)) {
            throw new \RuntimeException(sprintf('Generated file "%s" is missing from staging.', $filename));
        }
        if (!@rename($from, $to)) {
            throw new \RuntimeException(sprintf('Could not move "%s" into place at "%s".', $filename, $to));
        }
    }

    /**
     * Delete child sitemaps in the CURRENT child directory that this run did not produce.
     *
     * Scoped by a strict filename pattern so this can never touch anything the module did
     * not write — it may be running in the document root, where a loose glob would be an
     * unacceptable risk.
     *
     * @param string[] $keep
     * @return string[]
     */
    private function reapOrphans(array $keep, string $childDir): array
    {
        $removed = [];
        $keep = array_flip($keep);

        foreach ((array) glob(rtrim($childDir, '/') . '/sitemap-*.xml') as $path) {
            $name = basename($path);
            if (isset($keep[$name])) {
                continue;
            }
            if (!preg_match('/^sitemap-[A-Za-z0-9_-]+-\d+\.xml$/', $name)
                && !preg_match('/^sitemap-[A-Za-z0-9_-]+-[A-Za-z0-9_-]+-\d+\.xml$/', $name)) {
                continue;
            }
            if (@unlink($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /**
     * Clean up the PREVIOUS run's files when the layout or location changed.
     *
     * Switching target — or switching between single-file and index mode — otherwise
     * strands a complete, valid-looking sitemap at the old location. That is worse than
     * clutter: a stale index goes on advertising URLs and children that no longer track
     * the collection, and nothing in the application will ever correct it.
     *
     * This deletes ONLY the exact filenames the previous run recorded writing, from the
     * exact directories it recorded writing them to. No pattern guessing, so a
     * hand-maintained sitemap.xml that predates the module is never touched.
     *
     * @return string[]
     */
    private function reapPreviousRun(array $previous, array $childFiles, string $indexFile): array
    {
        if (empty($previous['files']) || !is_array($previous['files'])) {
            return [];
        }

        $currentIndexDir = $this->location->indexDirectory();
        $currentChildDir = $this->location->childDirectory();
        $previousIndexDir = (string) ($previous['indexDirectory'] ?? '');
        $previousChildDir = (string) ($previous['childDirectory'] ?? '');

        $stillCurrent = [];
        foreach ($childFiles as $file) {
            $stillCurrent[$currentChildDir . '/' . $file] = true;
        }
        $stillCurrent[$currentIndexDir . '/' . $indexFile] = true;

        $removed = [];
        foreach ($previous['files'] as $file) {
            $dir = ($file === ($previous['indexFile'] ?? 'sitemap.xml')) ? $previousIndexDir : $previousChildDir;
            if ($dir === '') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (isset($stillCurrent[$path]) || !is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
                $removed[] = $path;
            }
        }

        // A subdirectory we emptied is worth removing too, but only if it is genuinely empty.
        foreach ([$previousChildDir, $previousIndexDir] as $dir) {
            if ($dir !== '' && $dir !== $currentIndexDir && $dir !== $currentChildDir
                && basename($dir) === Location::SUBDIR && is_dir($dir)) {
                @rmdir($dir);
            }
        }

        return $removed;
    }

    private function removeDirectory(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function stagingDir(): string
    {
        return $this->stagingDir;
    }
}
