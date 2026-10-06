<?php
namespace StaticSitemaps\Service\Source;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Source\ItemsSource;

class ItemsSourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ItemsSource(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get(PathAssembler::class)
        );
    }
}
