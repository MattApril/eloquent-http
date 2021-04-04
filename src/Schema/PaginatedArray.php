<?php

namespace MattApril\EloquentHttp\Schema;


use MattApril\EloquentHttp\Contracts\PaginatedData;
use Illuminate\Support\Arr;

abstract class PaginatedArray implements PaginatedData
{

    /**
     * The data that has been paginated
     * @var array
     */
    protected $data;

    /**
     * Maps a key value to its location within the $data array.
     * Dot notation may be used for the value of each item.
     *
     * @var array
     */
    protected $keyMap = [
        'items' => null,
        'current_page' => null,
        'last_page' => null,
        'total' => null,
        'count' => null,
        'per_page' => null,
    ];

    /**
     * PaginatedArray constructor.
     * @param array $data
     */
    public function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * @return array
     */
    public function items() {
        return $this->getValueForKey('items');
    }

    /**
     * Get the current page.
     *
     * @return int
     */
    public function current_page() {
        return $this->getValueForKey('current_page');
    }

    /**
     * Get the last page.
     *
     * @return int|null
     */
    public function last_page() {
        return $this->getValueForKey('last_page');
    }

    /**
     * Get the total.
     *
     * @return int|null
     */
    public function total() {
        return $this->getValueForKey('total');
    }

    /**
     * Get the count.
     *
     * @return int
     */
    public function count() {
        return $this->getValueForKey('count');
    }

    /**
     * Get the number per page.
     *
     * @return int
     */
    public function per_page() {
        return $this->getValueForKey('per_page');
    }

    /**
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    protected function getValueForKey($key, $default=null) {
        return Arr::get($this->data, $this->getDataKey($key), $default);
    }

    /**
     * @param $key
     * @return mixed
     */
    protected function getDataKey($key) {
        return isset($this->keyMap[$key]) ? $this->keyMap[$key] : $key;
    }
}