<?php

/**
 * LIVE DEMO: the "overselling" race condition and how Atomic Locks fix it.
 *
 * 1. Start 10 servers (so requests really run in parallel, works on Windows too):
 *      php demo/serve.php
 * 2. In another terminal:
 *      php demo/demo.php
 *    Options:  TICKETS=1 BUYERS=50 php demo/demo.php   (PowerShell: $env:TICKETS=10; php demo/demo.php)
 *
 * Linux/macOS alternative for step 1: PHP_CLI_SERVER_WORKERS=10 php artisan serve --no-reload
 * then run the demo with SERVERS=1 (one URL, 10 workers behind it).
 */

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8000';
$servers = (int) (getenv('SERVERS') ?: 10);
// The parallel requests are spread over http://127.0.0.1:8000, :8001, ... (see demo/serve.php).
$port = (int) parse_url($base, PHP_URL_PORT);
$bases = array_map(fn ($i) => str_replace(":$port", ':'.($port + $i), $base), range(0, $servers - 1));
$tickets = (int) (getenv('TICKETS') ?: 1);
$buyers = (int) (getenv('BUYERS') ?: 50);

function request(string $method, string $url, array $body = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $out = curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) $out, true)];
}

/** $n different buyers press "Buy" at the SAME moment. */
function rush(string $path, int $n): array
{
    global $bases;
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 1; $i <= $n; $i++) {
        $ch = curl_init($bases[$i % count($bases)].$path); // spread over the servers
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['buyer' => "Buyer #$i"]),
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[] = $ch;
    }
    $start = microtime(true);
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);

    $codes = [];
    foreach ($handles as $ch) {
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        curl_multi_remove_handle($multi, $ch);
    }
    ksort($codes);

    return [$codes, microtime(true) - $start];
}

function line(string $text = ''): void
{
    echo $text.PHP_EOL;
}

function codes(array $codes): string
{
    $names = [201 => 'bought', 409 => 'sold out', 503 => 'busy, retry'];

    return implode(', ', array_map(
        fn ($code, $n) => "$n × $code ".($names[$code] ?? ''),
        array_keys($codes), $codes,
    ));
}

function run(string $label, string $base, string $path, int $tickets, int $buyers): array
{
    [$code, $reset] = request('POST', "$base/api/demo/reset", ['tickets' => $tickets]);
    if ($code !== 200) {
        line("Cannot reach $base - is `php demo/serve.php` running?");
        exit(1);
    }
    $id = $reset['event_id'];

    line();
    line("$label  POST ".str_replace('{id}', $id, $path));
    [$codes, $secs] = rush(str_replace('{id}', $id, $path), $buyers);
    [, $event] = request('GET', "$base/api/events/$id");

    line('   Responses:    '.codes($codes));
    line("   Tickets sold: {$event['tickets_sold']}   Tickets left: {$event['tickets_left']}   (".number_format($secs, 2).'s)');

    return $event;
}

line('==============================================================');
line(" $tickets ticket(s) left, $buyers fans press \"Buy\" at the same second");
line('==============================================================');

$before = run('❌ BEFORE (no lock)  ', $base, '/api/unsafe/events/{id}/buy', $tickets, $buyers);
$after = run('✅ AFTER  (Cache::lock)', $base, '/api/events/{id}/buy', $tickets, $buyers);
$rowLock = run('✅ AFTER  (lockForUpdate)', $base, '/api/row-lock/events/{id}/buy', $tickets, $buyers);

line();
line('--------------------------------------------------------------');
line(sprintf(' RESULT: no lock %d sold (stock %d)  |  Cache::lock %d sold (stock %d)  |  lockForUpdate %d sold (stock %d)',
    $before['tickets_sold'], $before['tickets_left'], $after['tickets_sold'], $after['tickets_left'],
    $rowLock['tickets_sold'], $rowLock['tickets_left']));
line('--------------------------------------------------------------');
