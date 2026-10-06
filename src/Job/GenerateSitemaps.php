<?php
namespace StaticSitemaps\Job;

use Omeka\Job\AbstractJob;
use StaticSitemaps\Generator\Pipeline;

/**
 * The generation job.
 *
 * A standard Omeka job, so an administrator-triggered run is visible and logged on the
 * usual job pages, and a cron run behaves identically.
 *
 * Concurrency: a lock prevents a cron run and an administrator run from writing at the
 * same time. Two runs sharing one staging directory would interleave their files and
 * publish a mixture of both. A stale lock is broken after LOCK_TTL so that a killed
 * process cannot wedge the feature permanently — which would be a worse failure than
 * the one the lock prevents.
 */
class GenerateSitemaps extends AbstractJob
{
    const LOCK_KEY = 'staticsitemaps_lock';
    const LOCK_TTL = 3600;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        if (!$this->acquireLock($settings, $logger)) {
            return;
        }

        try {
            /** @var Pipeline $pipeline */
            $pipeline = $services->get(Pipeline::class);

            $report = $pipeline->run(function ($message) use ($logger) {
                $logger->info('[StaticSitemaps] ' . $message);
            });

            // The pipeline persists its own report and published state, so a run
            // dispatched any other way records the same thing.
            $data = $report->toArray();

            if (!empty($data['error'])) {
                // Recorded in the report AND raised, so it appears both on the config
                // page and as a failed job rather than a silently "completed" one.
                $logger->err('[StaticSitemaps] ' . $data['error']);
                throw new \Omeka\Job\Exception\RuntimeException($data['error']);
            }

            $logger->info(sprintf(
                '[StaticSitemaps] Wrote %d URLs across %d files in %ss.',
                $data['totalUrls'],
                count($data['files']),
                $data['durationSeconds']
            ));
        } finally {
            $settings->set(self::LOCK_KEY, null);
        }
    }

    private function acquireLock($settings, $logger): bool
    {
        $lock = $settings->get(self::LOCK_KEY);
        if ($lock && (time() - (int) $lock) < self::LOCK_TTL) {
            $logger->warn(
                '[StaticSitemaps] Another generation run is already in progress; skipping this one.'
            );

            return false;
        }
        $settings->set(self::LOCK_KEY, time());

        return true;
    }
}
