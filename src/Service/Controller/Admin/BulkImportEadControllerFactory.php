<?php
namespace BulkImportEad\Service\Controller\Admin;

use BulkImportEad\Controller\Admin\BulkImportEadController;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class BulkImportEadControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new BulkImportEadController(
            $services->get('Config')
        );
    }
}
