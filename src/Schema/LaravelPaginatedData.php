<?php

namespace MattApril\EloquentHttp\Schema;

/**
 * Class LaravelPaginatedData
 * Standard laravel pagination model definition
 *
 * @package App\Support\Pagination
 */
class LaravelPaginatedData extends PaginatedArray
{
    protected $keyMap = [
        'items' => 'data',
        'current_page' => null,
        'last_page' => null,
        'total' => null,
        'count' => 'to',
        'per_page' => null,
    ];
}