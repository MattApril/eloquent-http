<?php

namespace MattApril\EloquentHttp\Tests;


use MattApril\EloquentHttp\Exception\ServiceValidationException;
use MattApril\EloquentHttp\RequestBuilder;
use MattApril\EloquentHttp\Schema\Schema;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\MessageBag;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tests\MocksHttpClients;
use Tests\TestCase;

class RequestBuilderTest extends TestCase
{
    use MocksHttpClients;
    use StubsModel;
    const MODEL_SERVICE_NAME = 'test-service';

    /**
     * For a GET request, the where clauses that do not match path variables should be added to the query string
     */
    public function testWhereIsSetOnQueryString() {

        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->where($queryParams=[
            'queryKey' =>'queryValue'
        ]);
        $requestBuilder->where('variable', 'pathValue'); # this matches a path variable, so should not be included in the query string
        $request = $requestBuilder->toRequest('test_get');

        $this->assertEquals(http_build_query($queryParams), $request->getUri()->getQuery());
    }

    /**
     * Both where() and query() should land up in the query string for get requests
     */
    public function testWhereAndQueryIsSetOnQueryString() {

        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->where($queryParams=[
            'queryKey' =>'queryValue'
        ]);
        $requestBuilder->query($queryParams2=[
            'queryKey2' =>'queryValue2'
        ]);
        $requestBuilder->path('variable', 'pathValue');
        $request = $requestBuilder->toRequest('test_get');

        $actualQueryParams = [];
        parse_str($request->getUri()->getQuery(), $actualQueryParams);
        $this->assertEquals(array_merge($queryParams, $queryParams2), $actualQueryParams);
    }

    /**
     * For POST requests without a body being sent, the WHERE clauses should go directly in the body.
     */
    public function testWheresSetInPostBody() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->where([
            'key1' => 'path1',
            'key2' => 'path2',
        ]);
        $requestBuilder->where($bodyParams=[
            'bodyKey' =>'bodyValue',
        ]);
        $request = $requestBuilder->toRequest('test_post');

        # NOTE: see newSchemaStub::makePayload below
        $this->assertEquals(http_build_query($bodyParams), $request->getBody()->getContents());
    }

    /**
     * For POST requests with a body being sent, the WHERE clauses should go in the query string.
     */
    public function testWheresSetInPostQueryString() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->where([
            'key1' => 'path1',
            'key2' => 'path2',
        ]);
        $requestBuilder->where($queryParams=[
            'bodyKey' =>'bodyValue',
        ]);
        $requestBuilder->body(['this is the request body']);
        $request = $requestBuilder->toRequest('test_post');

        # NOTE: see newSchemaStub::makePayload below
        $this->assertEquals(http_build_query($queryParams), $request->getUri()->getQuery());
    }

    /**
     * Verify that setting the path variable directly with path() works
     */
    public function testExplicitlySettingPathVariable() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $routes=$this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->path('variable', $pathVar='path_var');
        $request = $requestBuilder->toRequest('test_get');

        $this->assertEquals("/some/path/{$pathVar}", $request->getUri()->getPath());
    }

    /**
     * If both a path() and where() are set with a matching key, make sure they both get sent in the request
     * one in the path and one in the query string (or body for a POST)
     */
    public function testDuplicatePathVarAndWhereDoNotConflict() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $routes=$this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->path('variable', $pathVar='path_var');
        $requestBuilder->where('variable', $queryVar='query_var');
        $request = $requestBuilder->toRequest('test_get');

        # path parameters with the key "variable" should have been set in the request,
        # one in the path and one in the query string
        $this->assertEquals("/some/path/{$pathVar}", $request->getUri()->getPath());
        $this->assertEquals(http_build_query(['variable' => $queryVar]), $request->getUri()->getQuery());
    }

    /**
     * Headers can be added through Schema default headers and on a per request basis.
     */
    public function testAddHeader() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $routes=$this->routes(), $schema);
        $requestBuilder->setModel($model);
        $requestBuilder->headers('X-RandomHeader', $headerValue='MyValue');
        $request = $requestBuilder->where('variable', 123)->toRequest('test_get');

        # make sure both the Schema's default header is set, along with our custom header
        $this->assertEquals($headerValue, $request->getHeaderLine('X-RandomHeader'));
        $this->assertEquals('SomeValue', $request->getHeaderLine('TestHeader'));
    }

    /**
     * When no body is set on the request, the attributes of the model are to be used if any exist.
     */
    public function testModelPropertiesUsedInBody() {
        $model = $this->newModelStub();
        $model->fill($modelAttributes=[
            'name' => 'Matt',
            'occupation' => 'Software Developer',
            'age' => 29,
            'is_cool' => true,
        ]);
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model)
            ->where([
                'key1' => 'path1',
                'key2' => 'path2',
            ]);
        $request = $requestBuilder->toRequest('test_post');

        # verify that the model attributes were sent in the request body
        $bodyContents = [];
        parse_str($request->getBody()->getContents(), $bodyContents);
        $this->assertEquals($modelAttributes, $bodyContents);
    }

    /**
     * An explicitly defined body should be used instead of the attributes of the model attributes.
     */
    public function testDefinedBodyUsedInsteadOfModelAttributes() {
        $model = $this->newModelStub();
        $model->fill($modelAttributes=[
            'name' => 'Matt',
            'occupation' => 'Software Developer',
            'age' => 29,
            'is_cool' => true,
        ]);
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model)
            ->body($body=[
                'param1' => 'weeeeeee'
            ])
            ->where([
                'key1' => 'path1',
                'key2' => 'path2',
            ]);
        $request = $requestBuilder->toRequest('test_post');

        # verify that the data passed to body() was sent in the request body, and not the parameters set in where()
        $this->assertEquals($schema->makePayload($body), $request->getBody()->getContents());
    }

    /**
     * A raw body can be set, which will not utilize the schema.
     */
    public function testSetRawBody() {
        $model = $this->newModelStub();
        $model->fill($modelAttributes=[
            'name' => 'Matt',
            'occupation' => 'Software Developer',
            'age' => 29,
            'is_cool' => true,
        ]);
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->setModel($model)
            ->rawBody($body='can pass in a string as the raw body')
            ->where([
                'key1' => 'path1',
                'key2' => 'path2',
            ]);
        $request = $requestBuilder->toRequest('test_post');

        $this->assertEquals($body, $request->getBody()->__toString());
    }

    /**
     * Only named routes can be converted to requests.
     */
    public function testInvalidRouteRequest() {
        $this->expectException(\InvalidArgumentException::class);
        $schema = $this->newSchemaStub();

        $requestBuilder = new RequestBuilder($this->mockHttpClient([]), $this->routes(), $schema);
        $requestBuilder->toRequest('nope');
    }

    /**
     * Validation exceptions are dependent on the schema being used.
     * The schema will determine whether it is a validation exception or not, and how to extract the messages.
     */
    public function testValidationExceptionHandling() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();
        $http = $this->mockHttpClient([
            # create a valid validation error according the our schema definition.
            # 422 indicated a validation exception, and a valid error must contain the string 'ERROR' in the body..
            new Response(422, [], 'ERROR')
        ]);

        $requestBuilder = new RequestBuilder($http, $routes=$this->routes(), $schema);

        try {
            $requestBuilder->setModel($model)
                ->where('variable', 'path_var')
                ->test_get();
        } catch (\Exception $e) {
            $exception = $e;
        }

        # verify that the exception is a ServiceValidationException
        # and that the validation messages have been fetched from the schema
        $this->assertInstanceOf(ServiceValidationException::class, $exception);
        $this->assertEquals(getValidationErrorMessages(), $exception->errorBag);
    }

    /**
     * Inverse of testValidationExceptionHandling
     * Mkae sure that the original ClientException gets thrown if our schema does not understand the response.
     */
    public function testClientExceptionBubblesWhenInvalidErrorStructure() {
        $this->expectException(ClientException::class);

        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();
        $http = $this->mockHttpClient([
            # because the body does not contain the string 'ERROR' (as per our schema stub),
            # then this exception should not be treated as a validation exception.
            new Response(422, [], 'SUCCESS')
        ]);

        $requestBuilder = new RequestBuilder($http, $routes=$this->routes(), $schema);

            $requestBuilder->setModel($model)
                ->where('variable', 'path_var')
                ->test_get();
    }

    /**
     * When our builder determines that a request is being made to retrieve a single resource and it receives a 404
     * It will interpret this as a missing resource and return null rather than throw an exception.
     */
    public function test404NotFoundForSingleResource() {
        $model = $this->newModelStub();
        $schema = $this->newSchemaStub();
        $http = $this->mockHttpClient([
            # a valid error must contain the string 'ERROR' in the body.. according the our schema definition.
            new Response(404, [], 'this is an ERROR')
        ]);

        $requestBuilder = new RequestBuilder($http, $routes=$this->routes(), $schema);
        $result = $requestBuilder->setModel($model)
            ->where('id', 123) // having a path variable that matches the models primaryKey is critical here.
            ->request('find');

        $this->assertNull($result);
    }

    /**
     * Just some routes to test against our Builder
     * @return RouteCollection
     */
    private function routes() {
        $routes = new RouteCollection();
        $routes->add('test_get', new Route('/some/path/{variable}')); // if no method is set, it will default to GET
        $routes->add('test_post', (new Route('{key1}/path/{key2}'))->setMethods('POST'));
        $routes->add('find', (new Route('/path/{id}'))->setMethods('GET'));
        return $routes;
    }

    /**
     *
     */
    private function newSchemaStub() {
        return new class extends Schema {

            public function getDefaultHeaders(): array {
                return ['TestHeader' => 'SomeValue'];
            }

            public function makePayload(array $data) {
                # for testing we'll just build as a query string
                return http_build_query($data);
            }

            public function getPayload(): ?array {
                // just wrap the full string in an array..
                return [$this->response->getBody()->getContents()];
            }

            public function hasValidErrorStructure(): bool {
                # our schema expects that a valid error response contains the text 'ERROR'..
                return strpos($this->response->getBody()->getContents(), 'ERROR') !== false;
            }

            public function getValidationErrorMessages(): \Illuminate\Contracts\Support\MessageBag {
                return getValidationErrorMessages();
            }

            public function getClientErrorMessage(): string {
                return 'You did something wrong.';
            }
        };
    }
}

// TODO: this was only meant to be temporary..
function getValidationErrorMessages() {
    return new MessageBag([
        'test' => 'message text'
    ]);
}