<?php
namespace Ead\Service\Controller\Admin;

use Ead\Controller\Admin\EadController;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class EadControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new EadController(
            $services->get('Config')
        );
    }
}
