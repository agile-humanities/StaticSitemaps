<?php
namespace StaticSitemaps\Service\Source;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Source\SearchPagesSource;

class SearchPagesSourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchPagesSource(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ModuleManager'),
            $services->get('Omeka\Settings\Site'),
            $services->get(PathAssembler::class)
        );
    }
}
