<?php
namespace StaticSitemaps;

return [
    'service_manager' => [
        'factories' => [
            Config\ConfigOptions::class => Service\Config\ConfigOptionsFactory::class,
            Config\ConfigWriter::class => Service\Config\ConfigWriterFactory::class,
            Generator\PathAssembler::class => Service\Generator\PathAssemblerFactory::class,
            Generator\Pipeline::class => Service\Generator\PipelineFactory::class,
            Robots\RobotsAuditor::class => Service\Robots\RobotsAuditorFactory::class,
            Robots\RobotsFile::class => Service\Robots\RobotsFileFactory::class,
            Source\Manager::class => Service\Source\ManagerFactory::class,
            Target\ProxyBaseUriCheck::class => Service\Target\ProxyBaseUriCheckFactory::class,
        ],
        'invokables' => [
            Target\TargetProbe::class => Target\TargetProbe::class,
        ],
    ],

    // ------------------------------------------------------------------
    // The URL source registry.
    //
    // Adding a content type is one entry here — the configuration matrix is
    // generated from this registry, so a new source needs no UI work, and
    // another module can contribute one without touching this one.
    // ------------------------------------------------------------------
    'staticsitemaps_url_sources' => [
        'factories' => [
            'site-pages' => Service\Source\SitePagesSourceFactory::class,
            'items' => Service\Source\ItemsSourceFactory::class,
            'item-sets' => Service\Source\ItemSetsSourceFactory::class,
            // Registered unconditionally; the source reports isAvailable() === false
            // when AdvancedSearch is absent, and the matrix hides the column.
            'search-pages' => Service\Source\SearchPagesSourceFactory::class,
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'navigation' => [
        // Sidebar → Modules. Points at our own module-config page.
        'AdminModule' => [
            [
                'label' => 'Static sitemaps', // @translate
                'route' => 'admin/default',
                'params' => ['controller' => 'module', 'action' => 'configure'],
                'query' => ['id' => 'StaticSitemaps'],
                'resource' => 'Omeka\Controller\Admin\Module',
                'privilege' => 'browse',
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
