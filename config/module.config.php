<?php
namespace Ead;

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
            Controller\Admin\EadController::class => Service\Controller\Admin\EadControllerFactory::class,
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
                    'ead' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/ead[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Ead\Controller\Admin',
                                'controller' => Controller\Admin\EadController::class,
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
                'label' => 'EAD import', // @translate
                'route' => 'admin/ead',
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
