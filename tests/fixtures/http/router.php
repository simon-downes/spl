<?php declare(strict_types=1);
/*
 * Router for the `php -S` fixture server used by tests/HttpTest.php.
 * Each path emits one kind of response the HTTP client must handle.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {

    case '/json':
        $json = '{"name":"spl","items":[1,2]}';
        header('Content-Type: application/json');
        // explicit length so a HEAD request has a length but no body
        header('Content-Length: ' . strlen($json));
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            echo $json;
        }
        break;

    case '/headers':
        header('X-Custom: hello');
        header('X-Multi: one', false);
        header('X-Multi: two', false);
        header('Set-Cookie: a=1', false);
        header('Set-Cookie: b=2', false);
        header('Content-Type: text/plain');
        echo 'headers';
        break;

    case '/empty':
        http_response_code(204);
        break;

    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method'       => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'body'         => (string) file_get_contents('php://input'),
        ]);
        break;

    case '/redirect':
        header('X-Redirect: yes');
        header('Location: /json', true, 302);
        break;

    case '/see-other':
        header('Location: /echo', true, 303);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        break;

    case '/slow':
        sleep(2);
        echo 'slow';
        break;

    default:
        http_response_code(404);
        echo 'not found';

}

return true;
