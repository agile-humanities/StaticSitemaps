<?php
namespace StaticSitemaps\Service\Source;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Source\Manager;

class ManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');

        return new Manager($services, $config['staticsitemaps_url_sources'] ?? []);
    }
}
