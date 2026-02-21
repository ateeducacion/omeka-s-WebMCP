<?php
declare(strict_types=1);

namespace WebMCP;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'webmcp-proxy' => [
                        'type'    => 'Literal',
                        'options' => [
                            'route'    => '/webmcp/proxy',
                            'defaults' => [
                                '__NAMESPACE__' => 'WebMCP\Controller\Admin',
                                '__ADMIN__'     => true,
                                'controller'    => 'WebMCPProxy',
                                'action'        => 'proxy',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'invokables' => [
            'WebMCP\Controller\Admin\WebMCPProxy' => Controller\Admin\WebMCPProxyController::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type'        => 'gettext',
                'base_dir'    => dirname(__DIR__) . '/language',
                'pattern'     => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'WebMCP' => [
        'settings' => [
            'webmcp_enable_items'        => true,
            'webmcp_enable_media'        => true,
            'webmcp_enable_item_sets'    => true,
            'webmcp_enable_sites'        => true,
            'webmcp_enable_users'        => true,
            'webmcp_enable_vocabularies' => true,
            'webmcp_enable_bulk'         => true,
        ],
    ],
];
