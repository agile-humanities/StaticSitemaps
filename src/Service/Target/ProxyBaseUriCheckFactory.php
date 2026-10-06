<?php
namespace StaticSitemaps\Service\Target;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Target\ProxyBaseUriCheck;

class ProxyBaseUriCheckFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ProxyBaseUriCheck(
            $services->get('Omeka\ModuleManager'),
            $services->get('Omeka\Settings'),
            $services
        );
    }
}
