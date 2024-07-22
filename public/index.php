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
    name varchar(255) NOT NULL UNIQUE,
    image_url TINYTEXT NOT NULL,
    description TINYTEXT NOT NULL,
    likes BIGINT(6) UNSIGNED DEFAULT 0 NOT NULL,
    dislikes BIGINT(6) UNSIGNED DEFAULT 0 NOT NULL,
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

    $query = $params['query'] ?? "";
    $adult = filter_var($params['adult'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $ascending = filter_var($params['ascending'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $log->info("Searching for $query, ascending: $ascending, adult: $adult");

    $statement = $conn->prepare("SELECT * FROM Things WHERE name LIKE ?" . ($adult ? "" : " AND !adult") . " ORDER BY likes / (likes + dislikes) " . ($ascending ? "ASC" : "DESC") . " LIMIT 10");

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

    $params = json_decode($request->getBody(), true);

    $like_id = $params['like'];
    $dislike_id = $params['dislike'];

    if (!$like_id || !$dislike_id) {
        die("Request must contain both ids");
    }

    $like_statement = $conn->prepare("UPDATE Things SET likes = likes + 1 WHERE id = ?");
    $dislike_statement = $conn->prepare("UPDATE Things SET dislikes = dislikes + 1 WHERE id = ?");

    if (!$like_statement->execute([$like_id]) || !$dislike_statement->execute([$dislike_id])) {
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
    $response_body = array();

    // Input sanitisation

    function paramExists($key, &$array, &$response_body)
    {
        if (!array_key_exists($key, $array) || $array[$key] == "") {
            $response_body['status'] = 'error';
            $response_body['message'] = "Field cannot be empty";
            $response_body['param'] = $key;
            return false;
        }

        return true;
    }

    if (
        !paramExists('name', $params, $response_body) ||
        !paramExists('description', $params, $response_body) ||
        !paramExists('imageUrl', $params, $response_body) ||
        !paramExists('adult', $params, $response_body)
    ) {
        $response->getBody()->write(json_encode($response_body));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $name = $params['name'];
    $imageUrl = $params['imageUrl'];
    $description = $params['description'];
    $adult = filter_var($params['adult'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // Reject duplicate names

    $duplicate_name_statement = $conn->prepare('SELECT id FROM Things WHERE name = ? LIMIT 1');
    $duplicate_name_statement->bind_param('s', $name);

    if ($duplicate_name_statement->execute() === false) {
        $response_body['status'] = 'error';
        $response_body['message'] = 'Database error!';
        $response->getBody()->write(json_encode($response_body));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $result = $duplicate_name_statement->get_result();
    if ($result->num_rows != 0) {
        $response_body['rows'] = $result->num_rows;
        $response_body['status'] = 'error';
        $response_body['message'] = 'Name already used';
        $response_body['param'] = 'name';
        $response->getBody()->write(json_encode($response_body));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Validate image

    if (!isImage($imageUrl)) {
        $response_body['status'] = 'error';
        $response_body['message'] = 'Image URL must point to a valid image';
        $response_body['param'] = 'imageUrl';
        $response->getBody()->write(json_encode($response_body));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Submit thing

    $insert_statement = $conn->prepare("INSERT INTO Things (name, image_url, description, adult) VALUES (?, ?, ?, ?)");
    $insert_statement->bind_param("sssi", $name, $imageUrl, $description, $adult);

    if ($insert_statement->execute() === false) {
        $log->error($insert_statement->error);

        $response_body['status'] = 'error';
        $response_body['message'] = 'Database error!';
        $response->getBody()->write(json_encode($response_body));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $log->info("Served '/submit_thing' endpoint");

    $response_body['status'] = 'success';
    $response->getBody()->write(json_encode($response_body));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('[/{params:.*}]', function ($request, $response, array $args) {
    $response->getBody()->write("Unknown directory '" . $args['params'] . "'");
    return $response;
});

$app->run();

$conn->close();

// Thank-you danio https://stackoverflow.com/questions/676949/best-way-to-determine-if-a-url-is-an-image-in-php
function isImage($url)
{
    $params = array(
        'http' => array(
            'method' => 'HEAD'
        )
    );
    $ctx = stream_context_create($params);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp)
        return false;  // Problem with url

    $meta = stream_get_meta_data($fp);
    if ($meta === false) {
        fclose($fp);
        return false;  // Problem reading data from url
    }

    $wrapper_data = $meta["wrapper_data"];
    if (is_array($wrapper_data)) {
        foreach (array_keys($wrapper_data) as $hh) {
            if (substr($wrapper_data[$hh], 0, 19) == "Content-Type: image") // strlen("Content-Type: image") == 19 
            {
                fclose($fp);
                return true;
            }
        }
    }

    fclose($fp);
    return false;
}
