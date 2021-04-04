<?php

namespace MattApril\EloquentHttp\Tests;


use MattApril\EloquentHttp\Schema\JsonSchema;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class JsonSchemaTest extends TestCase
{

    public function testMakePayloadFromArray() {
        $schema = new JsonSchema();
        $payload = $schema->makePayload($data=[
            'name' => 'matt',
            'is_cool' => true,
            'fav_num' => 8,
            'dob' => null
        ]);
        $this->assertEquals(json_encode($data), $payload);
    }

    public function testGetPayload() {
        $response = new Response(200, [], json_encode($responseData=[
            'name' => 'Matt April',
            'age' => 29,
            'hasCat' => true
        ]));

        $schema = new JsonSchema();
        $schema->setResponse($response);
        $this->assertEquals($responseData, $schema->getPayload());
    }

    public function testGetEmptyPayload() {
        $response = new Response(200, [], '');

        $schema = new JsonSchema();
        $schema->setResponse($response);
        $this->assertNull($schema->getPayload());
    }

    public function testSetInvalidResponse() {
        $this->expectException(\JsonException::class);

        $response = new Response(200, [], '<h1>This is not json</h1>');
        $schema = new JsonSchema();
        $schema->setResponse($response);
    }

    public function testHasValidationError() {
        $response = new Response(422, [], json_encode(['username' => 'field is required']));
        $schema = new JsonSchema();
        $schema->setResponse($response);
        $this->assertTrue($schema->hasValidationError());
    }

    public function testDoesNotHaveValidationError() {
        $response = new Response(400, [], json_encode(['username' => 'field is required']));
        $schema = new JsonSchema();
        $schema->setResponse($response);
        $this->assertFalse($schema->hasValidationError());
    }

    public function testGetValidationErrorMessages() {
        $response = new Response(422, [], json_encode($errors=['username' => 'field is required']));
        $schema = new JsonSchema();
        $schema->setResponse($response);
        $actualErrors = $schema->getValidationErrorMessages();
        $this->assertEquals($errors, $actualErrors->getMessages());
    }

}
