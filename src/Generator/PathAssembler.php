<?php
namespace StaticSitemaps\Generator;

use Laminas\Router\RouteStackInterface;

/**
 * Assembles LOCAL paths from Omeka's routes, with any base path stripped.
 *
 * Two reasons this is not just a call to the Url view helper:
 *
 * 1. **Base path would be applied twice.** In a web request the router carries a base
 *    URL (Omeka installed in a subdirectory); in a CLI job it does not. The configured
 *    base URL (R4) already carries the subdirectory, so a router-supplied base path
 *    would be duplicated in exactly the installations that have one — and silently
 *    correct in the ones that do not, which is the worst kind of bug to find.
 * 2. **Determinism.** Config-page previews and job runs must produce identical paths,
 *    and they run in different contexts.
 *
 * Routes are still assembled through the router rather than string-formatted, so a
 * module that rewrites Omeka's routes stays honoured.
 */
class PathAssembler
{
    /** @var RouteStackInterface */
    private $router;

    public function __construct(RouteStackInterface $router)
    {
        $this->router = $router;
    }

    public function assemble(string $routeName, array $params = []): string
    {
        $path = $this->router->assemble($params, ['name' => $routeName]);

        return $this->stripBase($path);
    }

    /**
     * For paths we know literally (AdvancedSearch registers its site routes at
     * runtime as /s/:site-slug/{slug}, so they may not exist in every context we run in).
     */
    public function literal(string $path): string
    {
        return $this->stripBase($path);
    }

    private function stripBase(string $path): string
    {
        if (method_exists($this->router, 'getBaseUrl')) {
            $base = (string) $this->router->getBaseUrl();
            if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
                $path = substr($path, strlen($base));
            }
        }

        return $path === '' ? '/' : $path;
    }
}
