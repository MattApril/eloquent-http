<?php

namespace MattApril\EloquentHttp\Tests;


use MattApril\EloquentHttp\Contracts\PaginatedData;
use MattApril\EloquentHttp\Model;
use MattApril\EloquentHttp\Schema\JsonSchema;
use MattApril\EloquentHttp\Schema\LaravelPaginatedData;
use Carbon\Carbon;
use GuzzleHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Tests\MocksHttpClients;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use MocksHttpClients;
    use StubsModel;

    const MODEL_SERVICE_NAME = 'test-service';
    const MODEL_SERVICE_PATH = '/test/endpoint';

    /**
     * @var
     */
    protected $modelStub;

    /**
     *
     */
    public function setUp(): void {
        parent::setUp();

        # make a stub of our abstract Model class
        $this->modelStub = $this->newModelStub();

        $this->app['config']->set('services.'.self::MODEL_SERVICE_NAME, [
            'schema' => [
                'class' => JsonSchema::class, // TODO..?
                'pagination' => LaravelPaginatedData::class // TODO..?
            ]
        ]);
    }

    /**
     *
     */
    public function testGetKey() {
        $this->modelStub->id = $key = 88;
        $this->assertEquals($key, $this->modelStub->getKey());
    }

    /**
     * By default, our model should pull the base url from the services config:
     * services.{service}.domain
     */
    public function testGetServiceDomain() {
        $domain = 'http://domain.test';
        Config::set('services.' . self::MODEL_SERVICE_NAME . '.base_uri', $domain);
        $this->assertEquals( $domain, $this->modelStub->getBaseUri() );
    }

    /**
     * Request path arguments can be taken from the instance and the function arguments.
     */
    public function testPathVariablesFromWhereAndInstance() {
        $this->modelStub = new class extends Model {
            protected $remoteService = ModelTest::MODEL_SERVICE_NAME;
            protected $path = '/{param1}/something/{param2}/things';
            protected function getPaginatorModel(array $responseBody): PaginatedData {}
        };

        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200)
        ], $transactions);

        $this->modelStub->param1 = 'first';
        $this->modelStub->where('param2', 'second')->list();

        $this->assertEquals('/first/something/second/things', $transactions[0]['request']->getUri()->getPath());
    }

    /**
     * When path arguments are available via both the instance attributes and request builder methods,
     * the request builder arguments should take priority.
     */
    public function testPathVariablesFromMethodsHavePriorityOverModelAttributes() {
        $this->modelStub = new class extends Model {
            protected $remoteService = ModelTest::MODEL_SERVICE_NAME;
            protected $path = '/{param1}/something/{param2}/things';
            protected function getPaginatorModel(array $responseBody): PaginatedData {}
        };

        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200)
        ], $transactions);

        $this->modelStub->param1 = 'nah';
        $this->modelStub->param2 = 'nope';

        # both model attributes should be ignored in favor of the specified path argument and where clause
        $this->modelStub->where('param1', 'first')->path('param2', 'second')->list();

        $this->assertEquals('/first/something/second/things', $transactions[0]['request']->getUri()->getPath());
    }

    /**
     * The find() method should return an instance of the model from the route named "find"
     */
    public function testFind() {
        $id = 789;

        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], json_encode(['id' => $id]))
        ]);

        $model = $this->modelStub->find($id);

        $this->assertInstanceOf(get_class($this->modelStub), $model);
        $this->assertEquals($id, $model->id);
    }

    /**
     * The find method can be called statically, along with any other request method
     */
    public function testStaticFind() {
        $id = 789;

        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], json_encode(['id' => $id]))
        ]);

        $modelClass = get_class($this->modelStub);
        $model = $modelClass::find($id);

        $this->assertInstanceOf(get_class($this->modelStub), $model);
    }

    /**
     * The list() method should return a Collection of models from the remote service at $model->$path
     */
    public function testList() {
        $models = [
            $this->modelStub->newInstance(['id' => 100, 'name' => 'Matt1']),
            $this->modelStub->newInstance(['id' => 999, 'name' => 'Justin2']),
            $this->modelStub->newInstance(['id' => 10101, 'name' => 'April3']),
        ];

        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], json_encode($models))
        ]);

        $result = $this->modelStub->list();
        $this->assertEquals($this->modelStub->newCollection($models), $result);
    }

    /**
     * Models can be directly saved, just as in eloquent.
     * A big difference in this implementation is that a fresh instance with the most up to date properties
     * from the resource server will be returned. That way we can continue to use the original instance if desired,
     * Or replace it with the fresh instance.
     */
    public function testCreate() {
        $this->modelStub->name = 'Matt';
        $this->modelStub->favoriteNum = 8;
        $this->modelStub->isCool = true;
        $expectedPostBody = $this->modelStub->toJson();

        # this will represent the data being returned from the server,
        # note that an ID and timestamp are being set, neither of which existed on the original instance
        $createdModel = $this->modelStub->newInstance($this->modelStub->getAttributes());
        $createdModel->id = 12345;
        $createdModel->timestamp = '2020-01-01 00:00:00';

        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(201, [], $createdModel->toJson())
        ], $transactions);

        $createdInstance = $this->modelStub->save();

        # first make sure the newly id of the newly created record gets set on our original instance
        $this->assertEquals($createdModel->id, $this->modelStub->id);
        # next we want to make sure than a new instance was returned, which represents the fresh resource from the remote service.
        $this->assertNotSame($createdInstance, $this->modelStub);
        # and make sure the returned instance has the same properties as the original model we used
        $this->assertEquals($createdModel, $createdInstance);

        # verify that the origin model data was POSTED to resource server
        $this->assertEquals('POST', $transactions[0]['request']->getMethod());
        $this->assertEquals($expectedPostBody, $transactions[0]['request']->getBody()->getContents());
    }

    /**
     * Update acts almost the same as create, except that the id being set on the model triggers and update rather than create.
     */
    public function testUpdate() {
        $this->modelStub->id = 101;
        $this->modelStub->name = 'Matt';
        $this->modelStub->favoriteNum = 8;
        $this->modelStub->isCool = true;
        $this->modelStub->timestamp = '2020-01-01 00:00:00';

        # this will represent the data being returned from the server, note the difference in timestamp
        $updatedModel = $this->modelStub->newInstance($this->modelStub->getAttributes());
        $updatedModel->timestamp = '2020-12-31 12:12:12';

        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], $updatedModel->toJson())
        ], $transactions);

        $updatedInstance = $this->modelStub->save();

        # first make sure the newly id of the newly created record gets set on our original instance
        # next we want to make sure than a new instance was returned, which represents the fresh resource from the remote service.
        $this->assertNotSame($updatedInstance, $this->modelStub);
        # and make sure the returned instance has the same properties as the original model we used
        $this->assertEquals($updatedModel->toArray(), $updatedInstance->toArray());

        # verify that the origin model data was POSTED to resource server
        $this->assertEquals('PATCH', $transactions[0]['request']->getMethod());
        $this->assertEquals($this->modelStub->toJson(), $transactions[0]['request']->getBody()->getContents());
    }

    /**
     * Delete should trigger a request to the resources ID
     */
    public function testDelete() {
        $this->modelStub->id = 101;

        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(204)
        ], $transactions);

        $this->modelStub->delete();

        # verify that the origin model data was POSTED to resource server
        $this->assertEquals('DELETE', $transactions[0]['request']->getMethod());
        $expectedPath = self::MODEL_SERVICE_PATH .'/'. $this->modelStub->id;
        $this->assertEquals($expectedPath, $transactions[0]['request']->getUri()->getPath());
    }

    /**
     * Attempting to delete a model without an ID should fail.
     */
    public function testDeleteNoId() {
        $this->expectException(InvalidParameterException::class);
        $this->modelStub->delete();
    }

    /**
     * Making a request to the resource without specifying an ID implies that we are working with a list of resources
     * And since our modelStub is set to not utilize pagination, we should get a Collection instance back.
     *
     */
    public function testCollectionRequest() {
        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], '[]')
        ], $transactions);

        $requestQuery=[
            'param1' => 123,
            'param2' => true
        ];
        $builder = $this->modelStub->query($requestQuery);
        $collection = $builder->request('list');
        $this->assertInstanceOf(Collection::class, $collection);

        # verify that the request was made to the $path defined on the model.
        $this->assertEquals('GET', $transactions[0]['request']->getMethod());
        $this->assertEquals(self::MODEL_SERVICE_PATH, $transactions[0]['request']->getUri()->getPath());
        $this->assertEquals(http_build_query($requestQuery), $transactions[0]['request']->getUri()->getQuery());

        # verify that the complete response object can be fetched
        $this->assertSame($response, $builder->getLastResponse());
    }

    /**
     * When making a request to a specific ID it implies that any response data should map directly to a single model instance.
     */
    public function testSingleResourceRequest() {
        $id = 555;
        $transactions = [];
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(200, [], json_encode(['id' => $id]))
        ], $transactions);

        $builder = $this->modelStub->newRequest();
        $modelResult = $builder->find($id);
        $this->assertInstanceOf(get_class($this->modelStub), $modelResult);

        # verify that the request was made to the request $id, prefixed with the models $path
        $this->assertEquals('GET', $transactions[0]['request']->getMethod());
        $this->assertEquals(self::MODEL_SERVICE_PATH .'/'. $id, $transactions[0]['request']->getUri()->getPath());

        # verify that the complete response object can be fetched
        $this->assertSame($response, $builder->getLastResponse());
    }

    /**
     * If a resource is retrieved by ID and a 404 is returned by the server, then this should simply be returned as null
     * An error is not to be raised.
     */
    public function testGetSingleResource404() {
        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(404)
        ]);

        $result = $this->modelStub->find(555);
        $this->assertNull($result);
    }

    /**
     * If a 404 is received from a server when fetching a resource listing, then it indicates the requested path does not exist.
     * An error should be raised, as this is a configuration error.
     */
    public function testGetList404() {
        $this->expectException(GuzzleHttp\Exception\ClientException::class);

        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(404)
        ]);

        $this->modelStub->list();
    }

    /**
     * If a service returns a 500 error we will expect a server exception to be thrown.
     */
    public function testResourceServer500() {
        $this->expectException(GuzzleHttp\Exception\ServerException::class);

        $this->mockModelClient([
            $response = new GuzzleHttp\Psr7\Response(500)
        ]);

        $this->modelStub->request('create');
    }

    public function testDateAttribute() {
        $model = new class extends Model {
            protected $dates = ['Born'];
        };

        $model->fill([
            'Born' => $dateString='1991-02-08 12:00:00'
        ]);

        $this->assertEquals(Carbon::createFromFormat('Y-m-d H:i:s', $dateString), $model->Born);
    }

    public function testDateAttribute2() {
        $model = new class extends Model {
            protected $dates = ['Born'];
        };

        $model->fill([
            'Born' => $dateString='1991-02-08'
        ]);

        $this->assertEquals($dateString, $model->Born->format('Y-m-d'));
    }
}
