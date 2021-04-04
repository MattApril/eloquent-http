<?php

namespace MattApril\EloquentHttp\Schema;


use MattApril\EloquentHttp\Contracts\PaginatedData;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;

class JsonSchema extends Schema
{

    protected $responseData;
    protected $responsePayloadKey = null;

    /**
     * Headers that should be set on requests by default.
     * @var array
     */
    protected $defaultHeaders = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ];

    /**
     * Generate JSON encoded payload for given data set
     * @param array $data
     * @return string
     */
    public function makePayload(array $data): string {
        return json_encode($data);
    }

    /**
     * @param ResponseInterface $response
     * @throws \JsonException
     */
    public function setResponse(ResponseInterface $response) {
        parent::setResponse($response);

        $this->responseData = $responseData = null;

        # decode json once here for efficiency
        if( $responseContents = $response->getBody()->__toString() ){
            $responseData = json_decode($responseContents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \JsonException(json_last_error_msg() . ': ' . $responseContents);
            }
        }

        $this->responseData = $responseData;
    }

    /**
     * Gets payload containing model data
     * @return array|null
     */
    public function getPayload(): ?array {
        $payload = null;
        if (is_array($this->responseData)) {
            $payload = Arr::get($this->responseData, $this->responsePayloadKey, null);
        }

        return $payload;
    }

    /**
     * If pagination is supported then an implementation of PaginatedData should be returned
     * @return PaginatedData|null
     */
    protected function makePaginated(): ?PaginatedData {
        # TODO: this is not great..
        # and should also be passing headers in, as sometimes pagination data is found in headers.
        return isset($this->config['pagination'])
            ? new $this->config['pagination']($this->responseData)
            : null;
    }

    public function getValidationErrorMessages(): MessageBag {
        # by default we will just get the messages in the payload.
        # if a different structure is used for errors this will need to be overridden.
        $payload = $this->getPayload();
        $messages = new \Illuminate\Support\MessageBag();

        if(is_array($payload)) {
            $messages->merge($payload);
        }

        return $messages;
    }

    /**
     * @return string
     */
    public function getClientErrorMessage(): string {
        // TODO.. improve this should probably be abstract class
        return $this->responseData['message'] ?? '';
    }
}