<?php

namespace MattApril\EloquentHttp\Relations;


use MattApril\EloquentHttp\Model;
use MattApril\EloquentHttp\RequestBuilder;

abstract class HasOneOrMany extends Relation
{
    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The local key of the parent model.
     *
     * @var string
     */
    protected $localKey;


    /**
     * HasOneOrMany constructor.
     * @param RequestBuilder $request
     * @param Model $parent
     * @param $foreignKey
     * @param $localKey
     * @param $action string route name to make the request with
     */
    public function __construct(RequestBuilder $request, Model $parent, $foreignKey, $localKey, string $action)
    {
        $this->localKey = $localKey;
        $this->foreignKey = $foreignKey;

        parent::__construct($request, $parent, $action);
    }

    /**
     * Create and return an un-saved instance of the related model.
     *
     * @param  array  $attributes
     * @return Model
     */
    public function make(array $attributes = [])
    {
        return tap($this->related->newInstance($attributes), function ($instance) {
            $this->setForeignAttributesForCreate($instance);
        });
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->request->where($this->foreignKey, $this->getParentKey());
        }
    }

    /**
     * Attach a model instance to the parent model.
     *
     * @param  Model  $model
     * @return Model|false
     */
    public function save(Model $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a collection of models to the parent instance.
     *
     * @param  iterable  $models
     * @return iterable
     */
    public function saveMany($models)
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Set the foreign ID for creating a related model.
     *
     * @param  Model  $model
     * @return void
     */
    protected function setForeignAttributesForCreate(Model $model)
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
    }

    /**
     * Get the key value of the parent's local key.
     *
     * @return mixed
     */
    public function getParentKey()
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get the plain foreign key.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key for the relationship.
     *
     * @return string
     */
    public function getLocalKeyName()
    {
        return $this->localKey;
    }
}
