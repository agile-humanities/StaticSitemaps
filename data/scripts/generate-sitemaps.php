<?php declare(strict_types=1);
/**
 * Generate the sitemaps from the command line, for cron (R2.2).
 *
 * Usage:
 *   php modules/StaticSitemaps/data/scripts/generate-sitemaps.php --user-id 1
 *
 * Typical crontab entry, nightly at 02:15:
 *   15 2 * * * su -s /bin/sh www-data -c "php /path/to/omeka/modules/StaticSitemaps/data/scripts/generate-sitemaps.php --user-id 1"
 *
 * ⚠️ RUN AS THE WEB SERVER USER. A run as root creates sitemap files owned by root,
 * which the next run — as www-data, from the admin button — cannot replace. The
 * feature then fails in a way that looks like a permissions bug in the module.
 *
 * ⚠️ THE BOOTSTRAP MATTERS. Omeka\Mvc\Application::init() loads ACTIVE ADDON MODULES;
 * a plain Laminas init does not. Without it AdvancedMedia's file-store override never
 * registers (so media URLs would be raw storage URLs) and the AdvancedSearch source
 * disappears — both silently, in a file nobody reads before publishing it.
 *
 * This script is intentionally self-contained: cron support must not require another
 * module. Where EasyAdmin happens to be installed its generic task runner works too:
 *   php modules/EasyAdmin/data/scripts/task.php --task 'StaticSitemaps\Job\GenerateSitemaps' --user-id 1
 */

$omekaPath = dirname(__DIR__, 4);
require $omekaPath . '/bootstrap.php';

$options = getopt('u:h', ['user-id:', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Generate static sitemaps.

  -u --user-id [id]   Omeka user id to own the job. Required: without it the job has
                      no privileges and would read nothing.
  -h --help           This message.

Run as the web server user so the files it writes stay replaceable.

TXT
    );
    exit(0);
}

$userId = (int) ($options['u'] ?? $options['user-id'] ?? 0);
if ($userId < 1) {
    fwrite(STDERR, "A user id is required: --user-id 1\n");
    exit(1);
}

// Not a plain Laminas init — see the note above.
$application = \Omeka\Mvc\Application::init(require $omekaPath . '/application/config/application.config.php');
$services = $application->getServiceManager();

$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->find(\Omeka\Entity\User::class, $userId);
if (!$user) {
    fwrite(STDERR, sprintf("No user with id %d.\n", $userId));
    exit(1);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($user);

// Dispatched synchronously: cron already owns the process, and a job that outlives the
// cron run would make failures invisible to whatever is watching the exit status.
$dispatcher = $services->get('Omeka\Job\Dispatcher');
$job = $dispatcher->dispatch(
    \StaticSitemaps\Job\GenerateSitemaps::class,
    [],
    $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class)
);

$status = $job->getStatus();
if ($status === \Omeka\Entity\Job::STATUS_COMPLETED) {
    $report = $services->get('Omeka\Settings')->get(\StaticSitemaps\Config\ConfigOptions::LAST_RUN);
    fwrite(STDOUT, sprintf(
        "Sitemaps generated: %s URLs in %d files (%ss).\n",
        number_format($report['totalUrls'] ?? 0),
        count($report['files'] ?? []),
        $report['durationSeconds'] ?? '?'
    ));
    exit(0);
}

fwrite(STDERR, sprintf("Sitemap generation did not complete (status: %s). Check the job log.\n", $status));
exit(1);
