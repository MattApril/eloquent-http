# EloquentHttp
This is an Laravel Eloquent-like ORM for modeling data fetched over HTTP. It's designed primarily for use with RESTful APIs.
This package is useful if you are dealing with APIs as a primary data source, rather than a database.


## Configuration
Define a remote service in config/services.php

```
'service_name' => [
    'base_uri' => env('SERVICE_URL'),
    'schema' => [
        'class' => \MattApril\EloquentHttp\Schema\JsonSchema::class,
        'pagination' => \MattApril\EloquentHttp\Schema\LaravelPaginatedData::class,
    ]
],
```

## Setup
    TODO: Define your API schema and pagination stucture
    TODO: Assigning HTTP Client
    TODO: Define a model
    TODO: Define a relationship

## Usage

### Save a resource
```
$person = new Person([
    'name' => 'Matt'
]);
$person->save();
```

### Fetch a resource
```
$person = Person::find(88);
```
or
```
$person = new Person();
$person->newRequest()->find($id);
```

### Fetch related resources
One-to-one
```
$person = Person::find($id);
$address = $person->address;
```

One-to-many
```
$person = Person::find($id);
$parents = $person->parents;
```

One-to-many with additional filtering
```
$person = Person::find($id);
$sons = $person->kids()->where('gender', 'male')->list();
```

```
TODO: create a related resource
TODO: delete
TDOO: Custom requests (path, query, body, etc.)
```

## Advanced Topics

### Handling nested objects from your API
    TODO: example of using custom mutator to map a relationship automatically.
    
## To-do List
- Remove guzzle dependency and use php-http/httplug or similar
- Schema/pagination classes need a lot of work, possibly rethought entirely.
- list() should be renamed to get() if we want to stick to Eloquent conventions
- integrate tests with orchestral/testbench
- ...