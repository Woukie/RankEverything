<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Under construction! This is the Rank Everything backend. It will also probably be the distribution page.");
    return $response;
});

$app->get('/get_comparison', function (Request $request, Response $response, $args) {
    $data = array("Test", "Data", "Toyota");

    $response->getBody()->write(json_encode($data));
    return $response;
});

$app->get('[/{params:.*}]', function ($request, $response, array $args) {
    $response->getBody()->write("Unknown directory '" . $args['params'] . "'");
    return $response;
});

$app->run();
