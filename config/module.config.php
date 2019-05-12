<?php
namespace BulkImportEad;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'ead' => Service\ViewHelper\EadFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ImportForm::class => \Omeka\Form\Factory\InvokableFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\BulkImportEadController::class => Service\Controller\Admin\BulkImportEadControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'ead' => Service\ControllerPlugin\EadFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'bulk-import-ead' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/bulk-import-ead[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'BulkImportEad\Controller\Admin',
                                'controller' => Controller\Admin\BulkImportEadController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Bulk import EAD', // @translate
                'route' => 'admin/bulk-import-ead',
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
