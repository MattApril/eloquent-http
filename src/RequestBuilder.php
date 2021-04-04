<?php

namespace MattApril\EloquentHttp;


use MattApril\EloquentHttp\Exception\ServiceValidationException;
use MattApril\EloquentHttp\Contracts\PaginatedData;
use MattApril\EloquentHttp\Contracts\ServiceSchema;
use GuzzleHttp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Psr\Http\Message;
use Symfony\Component\Routing;

/**
 * Class RequestBuilder
 * Responsible for building requests and converting results in the context of a Model
 *
 * @author Matthew April
 */
class RequestBuilder
{

    /**
     * @var GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * @var bool
     */
    protected $async = false;

    /**
     * @var Routing\RouteCollection
     */
    protected $routes;

    /**
     * @var ServiceSchema
     */
    protected $schema;

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var array
     */
    protected $wheres = [];

    /**
     * @var array
     */
    protected $pathArgs = [];

    /**
     * @var array
     */
    protected $queryParams = [];

    /**
     * @var array
     */
    protected $formParams = [];

    /**
     * @var Message\StreamInterface|string|null
     */
    protected $body;

    /**
     * @var array
     */
    protected $headers = [];

    /**
     * @var Message\ResponseInterface
     */
    protected $response;

    /**
     * RequestBuilder constructor.
     *
     * @param GuzzleHttp\ClientInterface $client
     * @param Routing\RouteCollection $routes
     * @param ServiceSchema $schema
     */
    public function __construct(GuzzleHttp\ClientInterface $client, Routing\RouteCollection $routes, ServiceSchema $schema) {
        $this->client = $client;
        $this->routes = $routes;
        $this->schema = $schema;
    }

    /**
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model) {
        $this->model = $model;
        return $this;
    }

    /**
     * @return Model|null
     */
    public function getModel(): ?Model {
        return $this->model;
    }

    /**
     * @param $id
     * @param array $requestOptions
     * @return Model|PaginatorContract|Collection|null
     */
    public function find($id, array $requestOptions=[]) {
        return $this->where($this->model->getKeyName(), $id)
            ->request('find', $requestOptions);
    }

    /**
     * A shortcut operator for specifying a general parameter in a request.
     *
     * These parameters will land up in one of three places: the path, query string, or body.
     * The most appropriate location for the parameter will be determined by a
     * number of factors such as request method and other provided parameters.
     *
     * @param $key string|array
     * @param $value mixed|null
     * @return $this
     */
    public function where($key, $value=null) {
        $this->wheres = $this->setProperty($this->wheres, $key, $value);
        return $this;
    }

    /**
     * Explicitly set a URL path variable.
     *
     * @param $key string|array
     * @param $value mixed|null
     * @return $this
     */
    public function path($key, $value=null) {
        $this->pathArgs = $this->setProperty($this->pathArgs, $key, $value);
        return $this;
    }

    /**
     * Explicitly set a query string variable.
     *
     * @param $key string|array
     * @param $value mixed|null
     * @return $this
     */
    public function query($key, $value=null) {
        $this->queryParams = $this->setProperty($this->queryParams, $key, $value);
        return $this;
    }

    /**
     * Add headers to the request
     *
     * @param $header string|array
     * @param $value mixed|null
     * @return $this
     */
    public function headers($header, $value=null) {
        $this->headers = $this->setProperty($this->headers, $header, $value);
        return $this;
    }

    /**
     * Sets body of request.
     * Argument will be encoded using schema.
     *
     *
     * @param $body
     * @return $this
     */
    public function body(array $body) {
        $this->body = $this->schema->makePayload($body);
        return $this;
    }

    /**
     * Sets raw body of the request.
     *
     * @param $body
     * @return $this
     */
    public function rawBody($body) {
        $this->body = $body;
        return $this;
    }

    /**
     * @param bool $isAsync
     * @return $this
     */
    public function async(bool $isAsync=true) {
        $this->async = $isAsync;
        return $this;
    }

    /**
     * Makes a request to the resource server for a pre-defined action (route)
     *
     * @param string $action an action associated with a route.
     * @param array $requestOptions request options that get passed to the HTTP client.
     *
     * @return GuzzleHttp\Promise\PromiseInterface|Model|PaginatorContract|Collection|null
     */
    public function request(string $action, array $requestOptions=[]) {
        # map action to route
        if(!$route = $this->routes->get($action)) {
            throw new \InvalidArgumentException("No resource route with the name '{$action}' found.");
        }

        $request = $this->toRequest($action);
        $finalRequestOptions = array_merge($this->model->defaultRequestOptions(), $requestOptions);

        if($this->async) {
            return $this->asyncRequest($request, $finalRequestOptions, $route, $action);
        } else {
            return $this->syncRequest($request, $finalRequestOptions, $route, $action);
        }
    }

    /**
     * Converts a named route into an outbound request for this model
     *
     * @param string $routeName
     *
     * @return Message\RequestInterface
     */
    public function toRequest(string $routeName): Message\RequestInterface {
        # map action to route
        if(!$route = $this->routes->get($routeName)) {
            throw new \InvalidArgumentException("No resource route with the name '{$routeName}' found.");
        }

        # use the request method defined on the route, or GET as a default.
        $requestMethod = $route->getMethods()[0] ?? 'GET';

        # first build path arguments
        $pathArgs = $this->getPathArguments($route);

        # then build body for methods that allow it
        $body = null;
        if($this->schema->requestMethodAllowsBody($requestMethod)) {
            $body = $this->buildRequestBody();
        }

        # build the final URL with query string
        $urlGenerator = new Routing\Generator\UrlGenerator($this->routes, new Routing\RequestContext('', $requestMethod));
        $path = $urlGenerator->generate($routeName, $pathArgs);
        $uri = rtrim($this->model->getBaseUri(), '/') . '/' . ltrim($path, '/');

        if($queryParams = $this->buildQueryParameters()) {
            $uri .= '?' . $this->schema->buildQueryString($queryParams);
        }

        # and merge any request specific headers into the schema default headers
        $headers = array_merge($this->schema->getDefaultHeaders(), $this->headers);

        return new GuzzleHttp\Psr7\Request($requestMethod, $uri, $headers, $body);
    }

    /**
     * The last response that was successfully returned
     * @return Message\ResponseInterface
     */
    public function getLastResponse() {
        return $this->response;
    }

    /**
     * @param $request
     * @param $requestOptions
     * @param $route
     * @param $action
     *
     * @return Model|PaginatorContract|Collection|null
     */
    protected function syncRequest($request, $requestOptions, $route, $action) {
        try {
            $this->response = $this->client->send($request, $requestOptions);
            $this->schema->setResponse($this->response);

        } catch (\Exception $exception) {
            return $this->handleResourceRequestException($exception, $route, $action);
        }

        return $this->responseToResult($route, $this->response);
    }

    /**
     * @param $request
     * @param $requestOptions
     * @param $route
     * @param $action
     *
     * @return GuzzleHttp\Promise\PromiseInterface
     */
    protected function asyncRequest($request, $requestOptions, $route, $action) {
        $promise = $this->client->sendAsync($request, $requestOptions);
        return $promise->then(
            function (Message\ResponseInterface $response) use($route) {
                $this->schema->setResponse($this->response = $response);
                return $this->responseToResult($route, $this->response);
            },
            function (\Exception $exception) use($route, $action) {
                return $this->handleResourceRequestException($exception, $route, $action);
            }
        );
    }

    /**
     * @param Routing\Route $route
     * @param Message\ResponseInterface $response
     *
     * @return Model|PaginatorContract|Collection|null
     */
    protected function responseToResult(Routing\Route $route, Message\ResponseInterface $response) {
        $result = null;

        # verify that we have some content in the body before we try to determine what exactly it is.
        if( $response->getBody()->getSize() ){

            if( $this->isSingleResource($route) ){
                # we are expecting one instance or nothing in the responseData
                $payload = $this->schema->getPayload();
                $result = empty($payload) ? null : $this->model->newFromBuilder($payload);
            }

            # next check if the responseData was paginated
            elseif( $paginatedResponse = $this->schema->isPaginated() ){
                $result = $this->paginatedResponseToPaginator( $this->schema->getPaginated() );
            }

            # otherwise the responseData is likely to be a collection.
            elseif( !is_null($payload = $this->schema->getPayload()) ){
                $result = $this->model->hydrateFromArray($payload);
            }

        }

        return $result;
    }

    /**
     * Get an illuminate paginator instance from our PaginatedData model
     *
     * @param PaginatedData $paginated
     * @return PaginatorContract
     */
    protected function paginatedResponseToPaginator(PaginatedData $paginated): PaginatorContract {

        if($paginated->total() !== null) {
            # if we know how many total items we will get a length-aware instance
            $paginator = Container::getInstance()->makeWith(LengthAwarePaginator::class, [
                'items' => $this->model->hydrateFromArray($paginated->items()),
                'total' => $paginated->total(),
                'perPage' => $paginated->per_page(),
                'currentPage' => $paginated->current_page()
            ]);

        } else {
            $paginator = Container::getInstance()->makeWith(Paginator::class, [
                'items' => $this->model->hydrateFromArray($paginated->items()),
                'perPage' => $paginated->per_page(),
                'currentPage' => $paginated->current_page()
            ]);
        }

        return $paginator;
    }

    /**
     * Logic for determining when the server response should represent a single model
     *
     * @param Routing\Route $route
     *
     * @return bool
     */
    protected function isSingleResource(Routing\Route $route): bool {
        // TODO: should probably refer to the schema first and foremost.

        # first and foremost we will check if the path was targeted at a single resource
        # by checking to see if the path contained a variable matching the primaryKey of this model.
        if($this->isSingleResourceRoute($route)) {
            return true;
        }

        # however, there are other scenarios when the model identifier was not included
        # in the request path, such as when creating a new resource. In these cases we
        # will fall back to looking for the primaryKey in the response body.
        # NOTE: this cannot be the only method for checking, because some responses
        #       may not include the primaryKey even though we are dealing with a single resource,
        #       such as when specific fields are request from the server.
        $payload = $this->schema->getPayload();
        return isset( $payload[$this->model->getKeyName()] );
    }

    /**
     * @param Routing\Route $route
     * @return bool
     */
    protected function isSingleResourceRoute(Routing\Route $route) {
        $pathVariables = $route->compile()->getPathVariables();
        return in_array($this->model->getKeyName(), $pathVariables);
    }

    /**
     * Defines how resourceRequest exceptions are handled.
     *
     * @param \Throwable $exception
     * @param Routing\Route $route
     * @param string $routeName
     *
     * @return null
     * @throws \Throwable
     */
    protected function handleResourceRequestException(\Throwable $exception, Routing\Route $route, string $routeName) {
        if($exception instanceof GuzzleHttp\Exception\BadResponseException && ($response = $exception->getResponse()) ){
            $this->schema->setResponse($response);
        }

        # some errors can be handled gracefully..
        if($exception instanceof GuzzleHttp\Exception\ClientException && $this->schema->hasValidErrorStructure()) {
            if($exception->getCode() === 403) {
                throw new AuthorizationException();
            }

            # when we are attempting to fetch a single resource by id,
            # 404's are to be interpreted as non-existent rather than an error.
            if( $exception->getCode() === 404
                && strcasecmp($exception->getRequest()->getMethod(), 'GET') === 0
                && $this->isSingleResourceRoute($route)
            ){
                return null;
            }

            # re-throw validation errors as a custom validation exception so that it may be handled on a case by case basis.
            if($this->schema->hasValidationError()) {
                throw new ServiceValidationException($this->schema->getValidationErrorMessages(), $exception);
            }
        }

        throw $exception;
    }

    /**
     * @return array
     */
    protected function buildQueryParameters(): array {
        return array_merge($this->useWheres(), $this->queryParams);
    }

    /**
     *
     * @return mixed|null
     */
    protected function buildRequestBody() {

        # check if a custom body has been defined on the model,
        if( !is_null($this->body) ){
            $body = $this->body;

        } elseif( !empty($this->model->getAttributes()) ){
            # otherwise build payload from attributes using schema
            $body = method_exists($this->model, 'toBody')
                ? $this->model->toBody()
                : $this->schema->makePayload($this->model->getAttributes());

        } else {
            # finally, if no body was set, and no attributes are defined on the model,
            # simply use the given 'where' clauses.
            $body = $this->schema->makePayload($this->useWheres());
        }

        return $body;
    }

    /**
     * Get arguments for the given route
     *
     * @param Routing\Route $route
     *
     * @return array
     */
    protected function getPathArguments(Routing\Route $route): array {
        $pathArgs = $this->pathArgs;
        $requiredVariables = $route->compile()->getPathVariables();

        # determine if there are any arguments missing for the route.
        $missingArgs = array_diff($requiredVariables, array_keys($pathArgs));

        if(count($missingArgs) > 0) {
            # attempt to retrieve missing required path variables from other places..
            foreach ($missingArgs as $missingArg) {
                # first we will check any generic "wheres" that were set on this builder instance
                if(isset($this->wheres[$missingArg])) {
                    $pathArgs[$missingArg] = $this->useWhere($missingArg);
                } else {
                    # lastly, attempt to pull any missing path argument from the instance attributes.
                    $pathArgs[$missingArg] = $this->model->getAttribute($missingArg);
                }
            }
        }

        return $pathArgs;
    }

    /**
     * Use a defined "where" clause. It will be removed from the stack.
     *
     * @param $key
     * @return mixed
     */
    protected function useWhere($key) {
        $value = $this->wheres[$key];
        unset($this->wheres[$key]); # make sure to unset so it is only used once per request.
        return $value;
    }

    /**
     * Use all where clauses and empty the array
     *
     * @return array
     */
    protected function useWheres() {
        $wheres = $this->wheres;
        $this->wheres = [];
        return $wheres;
    }

    /**
     * @param $property array
     * @param $key array|string
     * @param $value mixed|null
     *
     * @return array
     */
    private function setProperty(array $property, $key, $value) {
        if(is_array($key)) {
            $property = array_merge($property, $key);
        } else {
            $property[$key] = $value;
        }

        return $property;
    }

    /**
     * @param $name
     * @param $arguments
     *
     * @return Model|PaginatorContract|Collection|null
     */
    public function __call($name, $arguments) {
        if(!$route = $this->routes->get($name)) {
            throw new \BadMethodCallException("No method could be matched for '{$name}'");
        }

        # verify that we didn't get an unexpected number of arguments
        if($count = count($arguments) > 1) {
            throw new \InvalidArgumentException("Method '{$name}' only supports 1 argument, {$count} given");
        }

        return $this->request($name, $arguments[0] ?? []);
    }

}