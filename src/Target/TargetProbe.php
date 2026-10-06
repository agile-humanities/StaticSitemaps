<?php
namespace StaticSitemaps\Target;

/**
 * Answers "can we actually write there, and is what we write actually served?"
 *
 * Two checks with deliberately DIFFERENT force, which is the point of the class:
 *
 *   • WRITABILITY IS A HARD GATE. If we cannot write, nothing works, and the failure
 *     must surface on the configuration page rather than inside a job an hour later.
 *     The docroot is root-owned on most real servers — it is writable in a typical
 *     Docker dev stack only because of the bind mount, which is exactly the sort of
 *     difference that makes this fail first in production.
 *
 *   • REACHABILITY IS A SOFT WARNING, NEVER A GATE. AdvancedMedia's reachability work
 *     established that a server frequently cannot fetch its own public URL — split
 *     horizon DNS, egress firewalls, container port mapping that the server does not
 *     share. A sitemap the server cannot self-fetch may still be perfectly visible to
 *     the outside world, so refusing on that basis would block a working configuration.
 *     We report what we found and let the administrator judge.
 */
class TargetProbe
{
    const PROBE_FILE = '.staticsitemaps-probe';

    /**
     * @return array{writable: bool, message: string, detail: string}
     */
    public function checkWritable(Location $location): array
    {
        // Both directories are checked. A document root that is writable does not
        // guarantee its /sitemaps/ subdirectory can be created, and a failure there
        // would otherwise only appear once a job was already running.
        $result = $this->checkDirectory($location->indexDirectory(), $location, false);
        if (!$result['writable']) {
            return $result;
        }
        if (!$location->isFlat()) {
            $child = $this->checkDirectory($location->childDirectory(), $location, true);
            if (!$child['writable']) {
                return $child;
            }
            $result['detail'] .= ' + ' . $location->childDirectory();
        }

        return $result;
    }

    /**
     * @param bool $mayCreate Whether this directory is ours to create if absent.
     */
    private function checkDirectory(string $dir, Location $location, bool $mayCreate): array
    {
        if (!is_dir($dir)) {
            $creatable = $mayCreate || $location->target() === Location::TARGET_FILES;
            if ($creatable) {
                if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return $this->fail(
                        'The chosen location cannot be created.', // @translate
                        sprintf('mkdir failed for "%s".', $dir)
                    );
                }
            } else {
                return $this->fail(
                    'The chosen location does not exist.', // @translate
                    sprintf('"%s" is not a directory.', $dir)
                );
            }
        }

        if (!is_writable($dir)) {
            return $this->fail(
                'The chosen location is not writable by the web server.', // @translate
                sprintf(
                    '"%s" is not writable by the current user (%s). On most servers the document '
                    . 'root is owned by root; either choose the files directory instead, or grant '
                    . 'the web server user write access to this directory.',
                    $dir,
                    $this->currentUser()
                )
            );
        }

        // is_writable() can disagree with reality (ACLs, read-only mounts, SELinux), so
        // prove it by actually creating and removing a file.
        $probe = $dir . '/' . self::PROBE_FILE;
        $token = bin2hex(random_bytes(8));
        if (@file_put_contents($probe, $token) === false) {
            return $this->fail(
                'The chosen location reports as writable, but a test file could not be created.', // @translate
                sprintf('file_put_contents failed for "%s".', $probe)
            );
        }
        @unlink($probe);

        return [
            'writable' => true,
            'message' => 'The chosen location is writable.', // @translate
            'detail' => $dir,
        ];
    }

    /**
     * Write a token file, fetch it over the public base URL, and see if it comes back.
     *
     * @return array{status: string, message: string, detail: string}
     *         status is 'served' | 'unverified' | 'skipped'
     */
    public function checkServed(Location $location, string $baseUrl): array
    {
        $dir = $location->indexDirectory();
        $probe = $dir . '/' . self::PROBE_FILE;
        $token = bin2hex(random_bytes(8));

        if (@file_put_contents($probe, $token) === false) {
            return [
                'status' => 'skipped',
                'message' => 'Could not test whether the location is web-visible.', // @translate
                'detail' => 'The probe file could not be written.',
            ];
        }

        $url = rtrim($baseUrl, '/') . $location->indexPublicPrefix() . '/' . self::PROBE_FILE;
        $body = $this->fetch($url);
        @unlink($probe);

        if ($body !== null && trim($body) === $token) {
            return [
                'status' => 'served',
                'message' => 'Files written here are served at the expected URL.', // @translate
                'detail' => $url,
            ];
        }

        return [
            'status' => 'unverified',
            'message' => 'Could not confirm that files written here are served at the expected URL.', // @translate
            'detail' => sprintf(
                'Fetching %s from the server itself did not return the expected content. This is '
                . 'often harmless: many servers cannot reach their own public URL even though '
                . 'visitors can. Check the sitemap in a browser once it has been generated.',
                $url
            ),
        ];
    }

    private function fetch(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (!empty($info['name'])) {
                return $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

    private function fail(string $message, string $detail): array
    {
        return ['writable' => false, 'message' => $message, 'detail' => $detail];
    }
}
