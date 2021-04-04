<?php

namespace MattApril\EloquentHttp\Schema;


use MattApril\EloquentHttp\Contracts\PaginatedData;
use MattApril\EloquentHttp\Contracts\ServiceSchema;
use Psr\Http\Message\ResponseInterface;

abstract class Schema implements ServiceSchema
{
    protected $config;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * Headers that should be set on requests by default.
     * @var array
     */
    protected $defaultHeaders = [];

    /**
     * Holds instance of PaginatedData if generated for the current response
     * @var PaginatedData
     */
    private $paginated;

    /**
     * Schema constructor.
     * @param array $config
     */
    public function __construct(array $config=[]) {
        $this->config = $config;
    }

    /**
     * Headers to be sent with all requests
     * @return array
     */
    public function getDefaultHeaders(): array {
        return $this->defaultHeaders;
    }

    /**
     * @param array $parameters
     * @return string
     */
    public function buildQueryString(array $parameters): string {
        # use the native php function to encode by default
        # if ever overriding this make sure to properly URL encode keys and values to prevent URL injection.
        return http_build_query($parameters);
    }

    /**
     * @param string $method
     * @return bool
     */
    public function requestMethodAllowsBody(string $method): bool {
        return strcasecmp($method, 'POST') === 0
            || strcasecmp($method, 'PUT') === 0
            || strcasecmp($method, 'PATCH') === 0;
    }

    /**
     * @param ResponseInterface $response
     * @return void
     */
    public function setResponse(ResponseInterface $response) {
        $this->response = $response;
        $this->paginated = null;
    }

    /**
     * Is the given response paginated?
     * @return bool
     */
    public function isPaginated(): bool {
        $paginated = $this->getPaginated();
        # if the paginated data is seeing a result set that appears to be paginated,
        # we will consider the response paginated.
        return !is_null($paginated->per_page()) && !is_null($paginated->current_page());
    }

    /**
     * @return PaginatedData
     */
    public function getPaginated(): PaginatedData {
        # optimization: if we already generated, just re-use it
        if(!is_null($this->paginated)) {
            return $this->paginated;
        }

        return $this->makePaginated();
    }

    /**
     * If pagination is supported then an implementation of PaginatedData should be returned
     * @return PaginatedData|null
     */
    protected function makePaginated(): ?PaginatedData {
        # TODO: this is not great..
        # and should also be passing headers in, as sometimes pagination data is found in headers.
        return isset($this->config['pagination'])
            ? new $this->config['pagination']($this->response)
            : null;
    }

    /**
     * Verify that the error structure appears as expected.
     * An invalid structure could indicate a problem such as: outdated API version, problem with API, etc.
     * @return bool
     */
    public function hasValidErrorStructure(): bool {
        // can override
        return true;
    }


    public function hasValidationError(): bool {
        return $this->response->getStatusCode() === 422;
    }
}