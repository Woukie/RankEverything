<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';

$dbhost = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$dbuser = $_ENV['DB_USER'];
$dbpassword = $_ENV['DB_PASSWORD'];

$log = new Logger('all');
$log->pushHandler(new StreamHandler('logs/all.log', Level::Info));

// Create connection
$log->info("Connecting to database '$dbname' on '$dbhost' as user '$dbuser'...");
$conn = new mysqli($dbhost, $dbuser, $dbpassword, $dbname, 3306);

// Check connection
if ($conn->connect_error) {
    $log->error("Connection to database failed: " . $conn->connect_error);
    die("Database is down, can't take traffic!");
}
$log->info("Connected to database successfully");

// Create things table
if (
    $conn->query("CREATE TABLE IF NOT EXISTS Things (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name TINYTEXT NOT NULL,
    image_url TINYTEXT NOT NULL,
    description TINYTEXT NOT NULL,
    votes BIGINT(6) UNSIGNED DEFAULT 0 NOT NULL,
    adult BOOLEAN NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )") === TRUE
) {
    $log->info("Table 'Things' created successfully");
} else {
    $log->error("Error creating table: " . $conn->error);
}

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    global $log;

    $log->info("Serving '/' endpoint");
    $response->getBody()->write("Under construction! This is the Rank Everything backend. It will also probably be the distribution page.");
    $log->info("Served '/' endpoint");

    return $response;
});

$app->get('/get_comparison[/{adult}]', function (Request $request, Response $response, $args) {
    global $conn, $log;
    $log->info("Serving '/get_comparison' endpoint");

    $adult = filter_var($args['adult'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // https://stackoverflow.com/a/41581041
    if (!$adult) {
        $statement = $conn->prepare("SELECT * FROM Things AS t1 JOIN (SELECT id FROM Things WHERE !adult ORDER BY RAND() LIMIT 2) as t2 ON t1.id=t2.id");
    } else {
        $statement = $conn->prepare("SELECT * FROM Things AS t1 JOIN (SELECT id FROM Things ORDER BY RAND() LIMIT 2) as t2 ON t1.id=t2.id");
    }

    $things = array();
    if ($statement->execute() === true) {
        $result = $statement->get_result();

        for ($i = 0; $i < $result->num_rows; $i++) {
            $thing = $result->fetch_assoc();
            array_push($things, $thing);
        }

        $response->getBody()->write(json_encode($things));
    } else {
        $log->error("Error getting comparison: " . $conn->error);
    }

    $log->info("Served '/get_comparison' endpoint");

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/search', function (Request $request, Response $response, $args) {
    global $conn, $log;

    $log->info("Serving '/search' endpoint");

    $params = json_decode($request->getBody(), true);

    if (!$params) {
        die("Request must contain a body");
    }

    if (
        !(
            array_key_exists('query', $params)
            && array_key_exists('ascending', $params)
        )
    ) {
        die('Must specify all parameters');
    }

    $query = $params['query'] ?? "";
    $adult = filter_var($params['adult'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $ascending = filter_var($params['ascending'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $log->info("Searching for $query, ascending: $ascending, adult: $adult");

    $statement = $conn->prepare("SELECT * FROM Things WHERE name LIKE ?" . ($adult ? "" : " AND !adult") . " ORDER BY votes " . ($ascending ? "ASC" : "DESC") . " LIMIT 10");

    $query = "%$query%";
    $statement->bind_param('s', $query);

    if ($statement->execute() === true) {
        $result = $statement->get_result();
        $things = array();

        for ($i = 0; $i < $result->num_rows; $i++) {
            $thing = $result->fetch_assoc();
            array_push($things, $thing);
        }

        $log->info("Successfully executed search");
        $response->getBody()->write(json_encode($things));
    } else {
        $log->error("Error searching: " . $conn->error);
    }

    $log->info("Served '/search' endpoint");

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/submit_vote', function (Request $request, Response $response, $args) {
    global $conn, $log;

    $log->info("Serving '/submit_vote' endpoint");

    $row = $request->getBody();

    if (!$row) {
        die("Request must contain a body");
    }

    $log->info("Voting for row: $row");
    $statement = $conn->prepare("UPDATE Things SET votes = votes + 1 WHERE id = ?");

    if ($statement->execute([$row]) === TRUE) {
        $log->info("Vote registered");
        $response->getBody()->write(true);
    } else {
        $log->error("Error submitting vote: " . $conn->error);
        die("Something went wrong");
    }

    $log->info("Served '/submit_vote' endpoint");

    return $response;
});

$app->post('/submit_thing', function (Request $request, Response $response, $args) {
    global $conn, $log;
    $log->info("Serving '/submit_thing' endpoint");

    $params = json_decode($request->getBody(), true);

    if (
        !(
            array_key_exists('name', $params)
            && array_key_exists('imageUrl', $params)
            && array_key_exists('description', $params)
            && array_key_exists('adult', $params)
        )
    ) {
        die('Must specify all parameters');
    }

    $name = $params['name'];
    $imageUrl = $params['imageUrl'];
    $description = $params['description'];
    $adult = filter_var($params['adult'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $insert_statement = $conn->prepare("INSERT INTO Things (name, image_url, description, adult) VALUES (?, ?, ?, ?)");
    $insert_statement->bind_param("sssi", $name, $imageUrl, $description, $adult);

    if ($insert_statement->execute() === TRUE) {
        $log->info("New record created successfully");
        $response->getBody()->write(true);
    } else {
        $log->error("Error submitting thing: " . $conn->error);
        die("Something went wrong");
    }

    $log->info("Served '/submit_thing' endpoint");

    return $response;
});

$app->get('[/{params:.*}]', function ($request, $response, array $args) {
    $response->getBody()->write("Unknown directory '" . $args['params'] . "'");
    return $response;
});

$app->run();

$conn->close();
