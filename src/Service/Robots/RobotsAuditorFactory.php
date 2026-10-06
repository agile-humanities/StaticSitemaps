<?php
namespace StaticSitemaps\Service\Robots;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Robots\RobotsAuditor;
use StaticSitemaps\Robots\RobotsFile;

class RobotsAuditorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new RobotsAuditor($services->get(RobotsFile::class));
    }
}
