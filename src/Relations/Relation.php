<?php

namespace MattApril\EloquentHttp\Relations;

use MattApril\EloquentHttp\Model;
use MattApril\EloquentHttp\RequestBuilder;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Support\Traits\Macroable;


abstract class Relation
{
    use ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * @var RequestBuilder
     */
    protected $request;

    /**
     * The parent model instance.
     *
     * @var Model
     */
    protected $parent;

    /**
     * The related model instance.
     *
     * @var Model
     */
    protected $related;

    /**
     * The route name to make the http request with
     * @var string
     */
    protected $action;

    /**
     * Indicates if the relation is adding constraints.
     *
     * @var bool
     */
    protected static $constraints = true;


    /**
     * Relation constructor.
     *
     * @param RequestBuilder $request
     * @param Model $parent
     * @param string $action
     */
    public function __construct(RequestBuilder $request, Model $parent, string $action) {
        $this->request = $request;
        $this->parent = $parent;
        $this->related = $request->getModel();
        $this->action = $action;

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    public static function noConstraints(\Closure $callback)
    {
        $previous = static::$constraints;

        static::$constraints = false;

        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    abstract public function addConstraints();

    /**
     * Get the results of the relationship.
     *
     * @param array $requestOptions
     * @return mixed
     */
    abstract public function getResults(array $requestOptions=[]);

    /**
     * Executes request for the $action
     *
     * @param array $requestOptions
     * @return Model|\Illuminate\Contracts\Pagination\Paginator|\Illuminate\Support\Collection|null
     */
    public function sendRequest(array $requestOptions=[]) {
        return $this->request->request($this->action, $requestOptions);
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param  array  $models
     * @param  string|null  $key
     * @return array
     */
    protected function getKeys(array $models, $key = null)
    {
        return collect($models)->map(function ($value) use ($key) {
            return $key ? $value->getAttribute($key) : $value->getKey();
        })->values()->unique(null, true)->sort()->all();
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return RequestBuilder
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get the parent model of the relation.
     *
     * @return Model
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the related model of the relation.
     *
     * @return Model
     */
    public function getRelated()
    {
        return $this->related;
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $result = $this->forwardCallTo($this->request, $method, $parameters);

        if ($result === $this->request) {
            return $this;
        }

        return $result;
    }

    /**
     * Force a clone of the underlying request builder when cloning.
     *
     * @return void
     */
    public function __clone()
    {
        $this->request = clone $this->request;
    }
}
