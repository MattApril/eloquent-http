<?php

namespace MattApril\EloquentHttp\Contracts;

/**
 * A common interface to model already paginated data
 */
interface PaginatedData
{
    /**
     * Get the current page.
     *
     * @return int
     */
    public function current_page();

    /**
     * Get the last page.
     *
     * @return int
     */
    public function last_page();

    /**
     * Get the total.
     *
     * @return int
     */
    public function total();

    /**
     * Get the count.
     *
     * @return int
     */
    public function count();

    /**
     * Get the number per page.
     *
     * @return int
     */
    public function per_page();

    /**
     * @return array
     */
    public function items();

    /**
     * Get the url for the given page.
     *
     * @param int $page
     *
     * @return string
     */
    //public function getUrl($page);
}
