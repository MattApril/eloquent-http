<?php

namespace MattApril\EloquentHttp\Tests;


use MattApril\EloquentHttp\Model;

trait StubsModel
{
    public function newModelStub() {
        return new class extends Model {
            protected $remoteService = ModelTest::MODEL_SERVICE_NAME;
            protected $path = ModelTest::MODEL_SERVICE_PATH ?? null;
        };
    }
}