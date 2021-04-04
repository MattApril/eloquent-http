<?php

namespace MattApril\EloquentHttp\Exception;


use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Class ServiceValidationException
 * Exception when a remote service returns a request validation exception.
 */
class ServiceValidationException extends Exception
{

    /**
     * @var MessageBag
     */
    public $errorBag;

    /**
     * ServiceValidationException constructor.
     *
     * @param MessageBag $errorBag
     * @param ClientException $previous
     */
    public function __construct(MessageBag $errorBag, ClientException $previous) {
        $this->errorBag = $errorBag;
        parent::__construct('A validation error was received from a Storm service.', $previous->getResponse()->getStatusCode(), $previous);
    }

    /**
     * Converts this exception into a standard Illuminate exception.
     * Useful if you are dealing with user input and you want them to see the errors returned from the remote service.
     *
     * @param array|null $keys keys of the messages to include in the ValidationException
     * @return ValidationException
     */
    public function toValidationException(array $keys=null) {
        $messages = $this->errorBag->getMessages();

        # optionally filter down to only the request $keys
        if(is_array($keys)) {
            $messages = array_intersect_key($messages, array_flip($keys));
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @param array $allowedKeys
     * @return bool
     */
    public function errorsAreOnlyFor(array $allowedKeys): bool {
        $errorKeys = $this->errorBag->keys();
        $matchedKeys = array_intersect($allowedKeys, $errorKeys);
        return count($matchedKeys) === count($errorKeys);
    }

    /**
     * Helper function for some commonly repeated logic that applies to handling
     * this exception when it may contain a mix of user input and other input.
     *
     * If it is acceptable to relay some of the errors directly to users you may
     * specify which fields they are, and if this exception is only made up of
     * validation errors for those fields then a ValidationException will be throw.
     * Otherwise, we will simply re-throw this exception.
     *
     * @param array $allowedKeys
     * @param string $redirectTo
     * @return \Exception
     */
    public function handleEndUserErrors(array $allowedKeys, string $redirectTo=null): \Exception {
        if($this->errorsAreOnlyFor($allowedKeys)) {
            return $this->toValidationException()->redirectTo($redirectTo);
        }

        return $this;
    }
}