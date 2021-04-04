<?php

namespace MattApril\EloquentHttp;


use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Symfony\Component\Routing;

/**
 * Class Model
 * An Eloquent-like Model that can interface with remote services over HTTP.
 *
 * @author Matthew April
 */
abstract class Model extends \Jenssegers\Model\Model
{

    use ForwardsCalls;
    use Concerns\HasRelationships;

    /**
     * The service this model utilizes. references config/services.php to fetch the domain.
     * Eloquent equivalent to 'connection'
     * @var string
     */
    protected $remoteService;

    /**
     * The default base path this resource is located at in the service
     * Eloquent equivalent to 'table'
     * @var string
     */
    protected $path = '/';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Attributes to cast as Carbon date instances
     * @var array
     */
    protected $dates = [];

    /**
     * @var Routing\RouteCollection
     */
    protected $routes;

    /**
     * @var ClientInterface
     */
    protected $http;

    /**
     * Http client for communicating with remote service
     * @var ClientInterface
     */
    protected static $defaultHttp;

    /**
     * Model constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes=[]) {
        parent::__construct($attributes);

        $this->routes = $this->registerRoutes(new Routing\RouteCollection());
    }

    /**
     * @param array $requestOptions
     * @return mixed
     */
    public function save(array $requestOptions=[]) {
        $creating = is_null($this->getKey());
        $saved = $creating ? $this->create($requestOptions) : $this->update($requestOptions);

        # add primary key to original model, if available
        $self = static::class;
        if($creating && $saved instanceof $self) {
            $this->{$this->primaryKey} = $saved->getKey();
        }

        # we will also return the newly created instance so that all fresh data from the remote server is available if needed
        return $saved;
    }

    /**
     * Gets primary key / id
     * @return mixed
     */
    public function getKey() {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the primary key for the model.
     *
     * @return string
     */
    public function getKeyName() {
        return $this->primaryKey;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey() {
        return Str::snake(class_basename($this)).'_'.$this->getKeyName();
    }

    /**
     * @param ClientInterface $http
     */
    public static function setDefaultHttpClient(ClientInterface $http=null) {
        static::$defaultHttp = $http;
    }

    /**
     * @param ClientInterface|null $http
     */
    public function setHttpClient(ClientInterface $http=null) {
        $this->http = $http;
    }

    /**
     * @return ClientInterface
     */
    public function httpClient() {
        return $this->http ?? static::$defaultHttp;
    }

    /**
     * @return array
     */
    public function defaultRequestOptions(): array {
        return [];
    }

    /**
     * @param array $attributes
     * @return static
     */
    public function newInstance($attributes = []) {
        $instance = parent::newInstance($attributes);

        if($this->http) {
            $instance->setHttpClient($this->http);
        }

        return $instance;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @param  array  $attributes
     * @return static
     */
    public function newFromBuilder($attributes = [])
    {
        $model = $this->newInstance([]);

        $model->forceFill($attributes);

        return $model;
    }

    /**
     * Create a new Collection instance.
     *
     * @param  array  $models
     * @return Collection
     */
    public function newCollection(array $models = []) {
        return new Collection($models);
    }

    /**
     * Collection of models from array, in static context
     * @param array $items
     * @return Collection
     */
    public static function hydrate(array $items) {
        $instance = new static;
        return $instance->hydrateFromArray($items);
    }

    /**
     * Collection of models from array, in object context
     *
     * @param array $items
     * @return Collection
     */
    public function hydrateFromArray(array $items) {
        return $this->newCollection(array_map(function ($item) {
            return $this->newFromBuilder($item);
        }, $items));
    }

    /**
     * @return RequestBuilder
     */
    public function newRequest() {
        # build a new schema instance for this classes service
        $schemaConfig = Config::get("services.{$this->remoteService}.schema");
        $schemaClass = $schemaConfig['class'];
        $schema = new $schemaClass($schemaConfig);

        # create a RequestBuilder instance
        return (new RequestBuilder($this->httpClient(), $this->routes, $schema))
            ->setModel($this);
    }

    /**
     * @param Routing\RouteCollection $routes
     * @return Routing\RouteCollection
     */
    protected function registerRoutes(Routing\RouteCollection $routes): Routing\RouteCollection {
        $keyPath = $this->path . "/{{$this->primaryKey}}";

        $routes->add('find',
            (new Routing\Route($keyPath))->setMethods('GET')
        );
        $routes->add('list',
            (new Routing\Route($this->path))->setMethods('GET')
        );
        $routes->add('create',
            (new Routing\Route($this->path))->setMethods('POST')
        );
        $routes->add('update',
            (new Routing\Route($keyPath))->setMethods('PATCH')
        );
        $routes->add('delete',
            (new Routing\Route($keyPath))->setMethods('DELETE')
        );

        return $routes;
    }

    /**
     * Get the remote host for this model
     * @return mixed
     */
    public function getBaseUri() {
        return Config::get("services.{$this->remoteService}.base_uri");
    }

    /**
     * @param string $key
     * @return mixed|void
     */
    public function getAttribute($key) {
        # get attribute value from attributes/mutator if available
        if (array_key_exists($key, $this->getAttributes()) || $this->hasGetMutator($key)) {
            $value = parent::getAttribute($key);

            // If the attribute is listed as a date, we will convert it to a DateTime
            // instance on retrieval, which makes it quite convenient to work with
            // date fields without having to create a mutator for each property.
            if ($value !== null
                && \in_array($key, $this->getDates(), false)) {
                return $this->asDateTime($value);
            }

            return $value;
        }

        # otherwise assume we are fetching a relationship

        // Here we will determine if the model base class itself contains this given key
        // since we don't want to treat any of those methods as relationships because
        // they are all intended as helper methods and none of these are relations.
        if (method_exists(self::class, $key)) {
            return;
        }

        return $this->getRelationValue($key);
    }

    /**
     * Get the attributes that should be converted to dates.
     *
     * @return array
     */
    public function getDates()
    {
        return $this->dates;
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * Taken directly from Laravel's HasAttributes trait -Matt
     * @return array
     */
    public function relationsToArray() {
        $attributes = [];

        foreach ($this->getArrayableItems($this->relations) as $key => $value) {
            // If the values implements the Arrayable interface we can just call this
            // toArray method on the instances which will convert both models and
            // collections to their proper array form and we'll set the values.
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }

            // If the value is null, we'll still go ahead and set it in this list of
            // attributes since null is used to represent empty relationships if
            // if it a has one or belongs to type relationships on the models.
            elseif (is_null($value)) {
                $relation = $value;
            }

            // If the relationships snake-casing is enabled, we will snake case this
            // key so that the relation attribute is snake cased in this returned
            // array to the developers, making this consistent with attributes.
            if (static::$snakeAttributes) {
                $key = Str::snake($key);
            }

            // If the relation value has been set, we will set it on this attributes
            // list for returning. If it was not arrayable or null, we'll not set
            // the value on the array because it is some type of invalid value.
            if (isset($relation) || is_null($value)) {
                $attributes[$key] = $relation;
            }

            unset($relation);
        }

        return $attributes;
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDateTime($value)
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Date::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof \DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'), $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if ($this->isStandardDateFormat($value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        $format = $this->getDateFormat();

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        if (Date::hasFormat($value, $format)) {
            return Date::createFromFormat($format, $value);
        }

        return Date::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isStandardDateFormat($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat ?: 'Y-m-d H:i:s';
    }

    /**
     * @param $method
     * @param $arguments
     * @return RequestBuilder
     */
    public function __call($method, $arguments) {
        return $this->forwardCallTo($this->newRequest(), $method, $arguments);
    }
}