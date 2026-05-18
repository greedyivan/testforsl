<?php

declare(strict_types=1);

return [

    /*
     |--------------------------------------------------------------------------
     | Конфигурация Swagger / OpenAPI для Notification Service
     |--------------------------------------------------------------------------
     |
     | Документация доступна по адресу: GET /api/documentation
     | JSON-спека:                       GET /docs
     |
     | В development-режиме установите L5_SWAGGER_GENERATE_ALWAYS=true,
     | чтобы спека перегенерировалась при каждом запросе к /api/documentation.
     */

    'default' => 'default',

    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'Notification Service API',
            ],

            'routes' => [
                // URL для Swagger UI — аналогично FastAPI /docs
                'api' => 'docs',
            ],

            'paths' => [
                'use_absolute_path'      => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
                'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
                'docs_json'              => 'api-docs.json',
                'docs_yaml'              => 'api-docs.yaml',
                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),

                // Директории, которые сканирует swagger-php для поиска аннотаций
                'annotations' => [
                    base_path('app'),
                ],
            ],
        ],
    ],

    'defaults' => [
        'routes' => [
            // URL для сырой JSON/YAML-спеки
            'docs'            => 'api-docs.json',
            'oauth2_callback' => 'api/oauth2-callback',

            'middleware' => [
                'api'             => [],
                'asset'           => [],
                'docs'            => [],
                'oauth2_callback' => [],
            ],

            'group_options' => [],
        ],

        'paths' => [
            // Куда сохраняется сгенерированная спека
            'docs'     => storage_path('api-docs'),
            'views'    => base_path('resources/views/vendor/l5-swagger'),
            'base'     => env('L5_SWAGGER_BASE_PATH', null),
            'excludes' => [],
        ],

        'scanOptions' => [
            'default_processors_configuration' => [],
            'analyser'                          => null,
            'analysis'                          => null,
            'processors'                        => [],
            'pattern'                           => null,
            'exclude'                           => [],
            'open_api_spec_version'             => env(
                'L5_SWAGGER_OPEN_API_SPEC_VERSION',
                \L5Swagger\Generator::OPEN_API_DEFAULT_SPEC_VERSION,
            ),
        ],

        'securityDefinitions' => [
            'securitySchemes' => [],
            'security'        => [],
        ],

        /*
         | Константы, доступные в аннотациях через L5_SWAGGER_CONST_*.
         | @OA\Server(url=L5_SWAGGER_CONST_HOST) → подставляет APP_URL из env.
         */
        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('APP_URL', 'http://localhost:8000'),
        ],

        /*
         | true  — пересобирать спеку при каждом GET /api/documentation.
         |         Удобно в development; в production оставить false.
         */
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

        'proxy' => false,

        'additional_config_url' => null,

        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', 'alpha'),

        // null — отключает онлайн-валидатор (не нужен в изолированной среде)
        'validator_url' => null,

        'ui' => [
            'display' => [
                'dark_mode'     => env('L5_SWAGGER_UI_DARK_MODE', false),
                // 'list'  — раскрывает только теги
                // 'full'  — раскрывает теги и операции
                // 'none'  — всё свёрнуто
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'list'),
                'filter'        => env('L5_SWAGGER_UI_FILTERS', true),
            ],

            'authorization' => [
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', false),
            ],
        ],
    ],
];
