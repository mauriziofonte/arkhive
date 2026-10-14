<?php

declare(strict_types=1);

// Router for `php -S` in HttpClientTest: a stand-in for the releases API and its asset downloads.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/json':
        header('Content-Type: application/json');
        echo json_encode(['tag_name' => 'v2.0.1', 'query' => $_SERVER['QUERY_STRING'] ?? '']);
        break;
    case '/redirect':
        header('Location: /json', true, 302);
        break;
    case '/headers':
        echo json_encode(['user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null, 'accept' => $_SERVER['HTTP_ACCEPT'] ?? null]);
        break;
    case '/big':
        echo str_repeat('x', 300000);
        break;
    case '/proxied':
        // a proxy receives the full URL in the request line
        echo $_SERVER['REQUEST_URI'];
        break;
    default:
        http_response_code(404);
        echo '{"message":"Not Found"}';
}
