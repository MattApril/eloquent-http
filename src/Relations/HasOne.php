<?php

namespace MattApril\EloquentHttp\Relations;


use MattApril\EloquentHttp\Model;

class HasOne extends HasOneOrMany
{

    /**
     * Get the results of the relationship.
     *
     * @param array $requestOptions
     * @return mixed
     */
    public function getResults(array $requestOptions=[])
    {
        if (is_null($this->getParentKey())) {
            return null;
        }

        return $this->sendRequest($requestOptions);
    }

    /**
     * @param Model $parent
     * @return mixed
     */
    public function newRelatedInstanceFor(Model $parent)
    {
        return $this->related->newInstance()->setAttribute(
            $this->getForeignKeyName(), $parent->{$this->localKey}
        );
    }
}
