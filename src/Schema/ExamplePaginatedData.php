<?php

namespace MattApril\EloquentHttp\Schema;

/**
 * Class LaravelPaginatedData
 * The pagination model expected from our Billing API
 *
 * @package App\Support\Pagination
 */
class BillingPaginatedData extends PaginatedArray
{
    protected $keyMap = [
        'items' => 'data',
        'current_page' => 'meta.pagination.current_page',
        'last_page' => 'meta.pagination.total_pages',
        'total' => 'meta.pagination.total',
        'count' => 'meta.pagination.count',
        'per_page' => 'meta.pagination.per_page',
    ];
}