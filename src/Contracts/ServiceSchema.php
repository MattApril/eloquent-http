<?php

namespace MattApril\EloquentHttp\Contracts;


use Illuminate\Contracts\Support\MessageBag;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface ServiceSchema
 * Common interface for making requests and handling responses to external services.
 */
interface ServiceSchema {

    /**
     * Get standard headers to be sent with every request
     * @return array
     */
    public function getDefaultHeaders(): array;

    /**
     * Determines which request methods allow for a body
     *
     * @param string $method
     * @return bool
     */
    public function requestMethodAllowsBody(string $method): bool;

    /**
     * Create a request payload from an array
     *
     * @param array $data
     * @return mixed
     */
    public function makePayload(array $data);

    /**
     * NOTE: make sure each parameter key and value gets URL encoded to prevent URL injection.
     * @param array $parameters
     * @return string
     */
    public function buildQueryString(array $parameters): string;

    /**
     * Set the response interface to be analyzed
     *
     * @param ResponseInterface $response
     * @return mixed
     */
    public function setResponse(ResponseInterface $response);

    /**
     * Gets response payload
     * @return array|null
     */
    public function getPayload(): ?array;

    /**
     * Checks if a response is paginated
     * @return bool
     */
    public function isPaginated(): bool;

    /**
     * Get the paginated data set as PaginatedData
     * @return PaginatedData
     */
    public function getPaginated(): PaginatedData;

    /**
     * Determines if the error response body appears valid.
     * @return bool
     */
    public function hasValidErrorStructure(): bool;

    /**
     * Is the response a validation error?
     *
     * @return bool
     */
    public function hasValidationError(): bool;

    /**
     * Get validation error messages per input field, if available.
     *
     * @return MessageBag
     */
    public function getValidationErrorMessages(): MessageBag;

    /**
     * Get error message text for debugging purposes
     *
     * @return string
     */
    public function getClientErrorMessage(): string;

}