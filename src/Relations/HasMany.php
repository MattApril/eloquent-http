<?php

namespace MattApril\EloquentHttp\Relations;


class HasMany extends HasOneOrMany
{
    /**
     * Get the results of the relationship.
     *
     * @param array $requestOptions
     * @return mixed
     */
    public function getResults(array $requestOptions=[])
    {
        return ! is_null($this->getParentKey())
            ? $this->sendRequest($requestOptions)
            : $this->related->newCollection();
    }
}
