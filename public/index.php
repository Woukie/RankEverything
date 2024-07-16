<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$dbhost = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$dbuser = $_ENV['DB_USER'];
$dbpassword = $_ENV['DB_PASSWORD'];

// Create connection
echo "Connecting to database '$dbname' on '$dbhost' as user '$dbuser'...";
$conn = new mysqli($dbhost, $dbuser, $dbpassword, $dbname, 3306);

// Check connection
if ($conn->connect_error) {
    die("Connection to database failed: " . $conn->connect_error);
}
echo "Connected to database successfully";

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

$conn->close();
