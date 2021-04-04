<?php

namespace MattApril\EloquentHttp\Relations;


use MattApril\EloquentHttp\Model;
use MattApril\EloquentHttp\RequestBuilder;

class BelongsTo extends Relation
{

    /**
     * The child model instance of the relation.
     *
     * @var Model
     */
    protected $child;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The associated key on the parent model.
     *
     * @var string
     */
    protected $ownerKey;

    /**
     * The name of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * BelongsTo constructor.
     *
     * @param RequestBuilder $request
     * @param Model $child
     * @param $foreignKey
     * @param $ownerKey
     * @param $action
     * @param $relationName
     */
    public function __construct(RequestBuilder $request, Model $child, $foreignKey, $ownerKey, string $action, $relationName)
    {
        $this->ownerKey = $ownerKey;
        $this->foreignKey = $foreignKey;
        $this->relationName = $relationName;

        // In the underlying base relationship class, this variable is referred to as
        // the "parent" since most relationships are not inversed. But, since this
        // one is we will create a "child" variable for much better readability.
        $this->child = $child;

        parent::__construct($request, $child, $action);
    }

    /**
     * Get the results of the relationship.
     *
     * @param array $requestOptions
     * @return mixed
     */
    public function getResults(array $requestOptions=[])
    {
        if (is_null($this->child->{$this->foreignKey})) {
            return null;
        }

        return $this->sendRequest($requestOptions);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->request->where($this->ownerKey, $this->child->{$this->foreignKey});
        }
    }

    /**
     * Make a new related instance for the given model.
     *
     * @return Model
     */
    protected function newRelatedInstanceFor()
    {
        return $this->related->newInstance();
    }

    /**
     * Get the child of the relationship.
     *
     * @return Model
     */
    public function getChild()
    {
        return $this->child;
    }

    /**
     * Get the foreign key of the relationship.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the associated key of the relationship.
     *
     * @return string
     */
    public function getOwnerKeyName()
    {
        return $this->ownerKey;
    }

    /**
     * Get the name of the relationship.
     *
     * @return string
     */
    public function getRelationName()
    {
        return $this->relationName;
    }
}
