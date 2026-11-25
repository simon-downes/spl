<?php declare(strict_types=1);
/*
 * Router for the `php -S` fixture server used by tests/HttpTest.php.
 * Each path emits one kind of response the HTTP client must handle.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// /r301, /r302, /r303, /r307 redirect to /echo with that status
if (preg_match('~^/r(30[1237])$~', $path, $matches)) {
    header('Location: /echo', true, (int) $matches[1]);
    return true;
}

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

    case '/form':
        // PHP only parses POST bodies itself; other methods need request_parse_body()
        header('Content-Type: application/json');
        echo json_encode([
            'method'       => $_SERVER['REQUEST_METHOD'],
            'content_type' => explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0],
            'fields'       => $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : request_parse_body()[0],
        ]);
        break;

    case '/to-ftp':
        header('Location: ftp://127.0.0.1:1/x', true, 302);
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
