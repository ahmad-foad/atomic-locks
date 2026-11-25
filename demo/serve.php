<?php

/**
 * Starts N PHP servers in parallel (ports 8000, 8001, ...) for the live demo.
 *
 * Why: PHP's built-in server handles ONE request at a time. On Linux/macOS
 * `PHP_CLI_SERVER_WORKERS=10 php artisan serve --no-reload` gives 10 workers,
 * but PHP_CLI_SERVER_WORKERS is NOT supported on Windows. Several servers on
 * different ports work everywhere: demo.php spreads its requests over them,
 * so the requests really run at the same time (like a real web server).
 *
 *   php demo/serve.php            # 10 servers, ports 8000-8009
 *   SERVERS=4 php demo/serve.php  # 4 servers
 *
 * Stop with Ctrl+C.
 */

$servers = (int) (getenv('SERVERS') ?: 10);
$firstPort = (int) (getenv('PORT') ?: 8000);
$root = dirname(__DIR__);
$router = $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$processes = [];

for ($i = 0; $i < $servers; $i++) {
    $port = $firstPort + $i;
    $processes[] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root.'/public', $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        $root.'/public', // Laravel's router expects to run from public/
    );
}

echo "Started $servers servers: http://127.0.0.1:$firstPort ... http://127.0.0.1:".($firstPort + $servers - 1).PHP_EOL;
echo "Swagger UI: http://127.0.0.1:$firstPort/api/documentation".PHP_EOL;
echo 'Now run `php demo/demo.php` in another terminal. Press Ctrl+C to stop.'.PHP_EOL;

register_shutdown_function(function () use ($processes) {
    foreach ($processes as $process) {
        proc_terminate($process);
    }
});

while (true) {
    foreach ($processes as $process) {
        if (! proc_get_status($process)['running']) {
            echo 'A server stopped (is the port already in use?).'.PHP_EOL;
            exit(1);
        }
    }
    sleep(1);
}
