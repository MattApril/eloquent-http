<?php

namespace MattApril\EloquentHttp\Tests;


use MattApril\EloquentHttp\Exception\ServiceValidationException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class ServiceValidationExceptionTest extends TestCase
{

    /**
     * Verify that errorBag and previous exception are set properly
     */
    public function testInitialize() {
        $errors = new MessageBag([
            'field' => 'error message'
        ]);
        $request = \Mockery::mock(RequestInterface::class);
        $clientException = new ClientException('message', $request, new Response(400));
        $exception = new ServiceValidationException($errors, $clientException);

        $this->assertSame($errors, $exception->errorBag);
        $this->assertSame($clientException, $exception->getPrevious());
    }

    /**
     * ServiceValidationException can be converted into a regular Illuminate ValidationException
     * This is a handy feature when you are sending user input directly to a remote service.
     * This is an easy method of relaying validation errors to the end user.
     */
    public function testToValidationException() {
        $errors = new MessageBag([
            'field' => 'error message'
        ]);
        $request = \Mockery::mock(RequestInterface::class);
        $clientException = new ClientException('message', $request, new Response(400));
        $exception = new ServiceValidationException($errors, $clientException);

        $illuminateValidationException = $exception->toValidationException();
        $this->assertEquals($errors->toArray(), $illuminateValidationException->errors());
    }

    /**
     * Sometimes you may only want to relay some of the validation messages to users.
     * This may be the case when you are sending a mix of user input and other data in your request.
     */
    public function testSomeKeysToValidationException() {
        $publicErrors = new MessageBag(['public_field' => 'error message']);
        $errors = new MessageBag([
            'private_field' => 'this is not for prying eyes'
        ]);
        $errors->merge($publicErrors);

        $request = \Mockery::mock(RequestInterface::class);
        $clientException = new ClientException('message', $request, new Response(400));
        $exception = new ServiceValidationException($errors, $clientException);

        $illuminateValidationException = $exception->toValidationException($publicErrors->keys());
        $this->assertEquals($publicErrors->toArray(), $illuminateValidationException->errors());
    }

    /**
     *
     */
    public function testErrorsAreOnlyForKeys() {
        $errors = new MessageBag([
            'field1' => 'a',
            'field2' => 'b',
        ]);

        $request = \Mockery::mock(RequestInterface::class);
        $clientException = new ClientException('message', $request, new Response(400));
        $exception = new ServiceValidationException($errors, $clientException);

        $this->assertTrue( $exception->errorsAreOnlyFor(['field1', 'field2']) );
        $this->assertTrue( $exception->errorsAreOnlyFor(['field1', 'field2', 'other_field']) );
        $this->assertFalse( $exception->errorsAreOnlyFor(['field1']) );
        $this->assertFalse( $exception->errorsAreOnlyFor(['field1', 'other_field']) );
        $this->assertFalse( $exception->errorsAreOnlyFor(['other_field']) );
    }

    /**
     *
     */
    public function testHandleEndUserErrors() {
        $errors = new MessageBag([
            'field1' => 'a'
        ]);

        $request = \Mockery::mock(RequestInterface::class);
        $clientException = new ClientException('message', $request, new Response(400));
        $exception = new ServiceValidationException($errors, $clientException);

        $this->assertInstanceOf(ValidationException::class, $exception->handleEndUserErrors(['field1']));
        $this->assertInstanceOf(get_class($exception), $exception->handleEndUserErrors(['other_field']));
    }
}