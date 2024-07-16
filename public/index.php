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
echo "<br> Connected to database successfully";

// Create things table
if (
    $conn->query("CREATE TABLE IF NOT EXISTS Things (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name TINYTEXT NOT NULL,
    image_url TINYTEXT NOT NULL,
    description TINYTEXT NOT NULL,
    votes BIGINT(6) UNSIGNED NOT NULL,
    adult BOOLEAN NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )") === TRUE
) {
    echo "<br> Table 'Things' created successfully";
} else {
    echo "<br> Error creating table: " . $conn->error;
}

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("<br> Under construction! This is the Rank Everything backend. It will also probably be the distribution page.");
    return $response;
});

$app->get('/get_comparison', function (Request $request, Response $response, $args) {
    $data = array("Test", "Data", "Toyota");

    $response->getBody()->write(json_encode($data));
    return $response;
});

$app->post('/submit_thing', function (Request $request, Response $response, $args) {
    global $conn;

    $params = (array) $request->getParsedBody();

    if (
        !(
            array_key_exists('name', $params)
            && array_key_exists('imageUrl', $params)
            && array_key_exists('description', $params)
            && array_key_exists('votes', $params)
            && array_key_exists('adult', $params)
        )
    ) {
        die('Must specify all parameters');
    }

    $name = $params['name'];
    $imageUrl = $params['imageUrl'];
    $description = $params['description'];
    $votes = $params['votes'];
    $adult = $params['adult'];

    if (
        $conn->query("INSERT INTO Things (name, image_url, description, votes, adult)
VALUES ($name, $imageUrl, $description, $votes, $adult)") === TRUE
    ) {
        echo "<br> New record created successfully";
    } else {
        echo "<br> Error submitting thing: " . $conn->error;
    }
    return $response;
});

$app->get('[/{params:.*}]', function ($request, $response, array $args) {
    $response->getBody()->write("Unknown directory '" . $args['params'] . "'");
    return $response;
});

$app->run();

$conn->close();
