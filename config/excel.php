<?php

use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

return [
    'exports' => [
        'chunk_size'             => 1000,
        'pre_calculate_formulas' => false,
        'strict_null_comparison' => false,
        'csv'                    => [
            'delimiter'              => ',',
            'enclosure'              => '"',
            'line_ending'            => PHP_EOL,
            'use_bom'                => false,
            'include_separator_line' => false,
            'excel_compatibility'    => false,
            'output_encoding'        => '',
            'test_auto_detect'       => true,
        ],
        'properties'             => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],
    ],

    'imports'            => [
        'read_only'    => true,
        'ignore_empty' => false,
        'heading_row'  => [
            'formatter' => 'slug',
        ],
        'csv'          => [
            'delimiter'        => null,
            'enclosure'        => '"',
            'escape_character' => '\\',
            'contiguous'       => false,
            'input_encoding'   => Csv::GUESS_ENCODING,
        ],
        'properties'   => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],
        'cells'        => [
            'middleware' => [
                //\Maatwebsite\Excel\Middleware\TrimCellValue::class,
                //\Maatwebsite\Excel\Middleware\ConvertEmptyCellValuesToNull::class,
            ],
        ],
    ],

    'extension_detector' => [
        'xlsx'     => Excel::XLSX,
        'xlsm'     => Excel::XLSX,
        'xltx'     => Excel::XLSX,
        'xltm'     => Excel::XLSX,
        'xls'      => Excel::XLS,
        'xlt'      => Excel::XLS,
        'ods'      => Excel::ODS,
        'ots'      => Excel::ODS,
        'slk'      => Excel::SLK,
        'xml'      => Excel::XML,
        'gnumeric' => Excel::GNUMERIC,
        'htm'      => Excel::HTML,
        'html'     => Excel::HTML,
        'csv'      => Excel::CSV,
        'tsv'      => Excel::TSV,
        'pdf'      => Excel::DOMPDF,
    ],

    'value_binder'       => [
        'default' => Maatwebsite\Excel\DefaultValueBinder::class,
    ],

    'cache'        => [
        /*
        |--------------------------------------------------------------------------
        | Redis caching for large files
        |--------------------------------------------------------------------------
        |
        | Using 'illuminate' driver with Redis to handle large Excel files
        | Redis is faster and more memory-efficient than filesystem batch cache
        |
        */
        'driver'      => 'illuminate',

        'batch'       => [
            'memory_limit' => 500000,
        ],

        'illuminate'  => [
            'store' => 'redis',
        ],

        'default_ttl' => 3600,
    ],

    'transactions' => [
        'handler' => 'db',
        'db'      => [
            'connection' => null,
        ],
    ],

    'temporary_files' => [
        'local_path'          => storage_path('framework/cache/laravel-excel'),
        'local_permissions'   => [
            'dir'  => 0755,
            'file' => 0644,
        ],
        'remote_disk'         => null,
        'remote_prefix'       => null,
        'force_resync_remote' => null,
    ],
];
