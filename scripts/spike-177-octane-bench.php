<?php

/*
 * Spike #177: is Laravel Octane worth it for ARE? (Not for main.)
 *
 * Runs one request scenario two ways, in a single CLI process and with no
 * server or open port:
 *
 *   fpm     a fresh Application is bootstrapped for every request, as PHP-FPM
 *           does (with cached config and routes, as `php artisan optimize`
 *           leaves them in production). Classes stay loaded between
 *           iterations, which FPM does not get even with opcache, so this
 *           UNDERSTATES Octane's gain.
 *   octane  the app is booted once and each request goes through Octane's
 *           real Worker: the per-request sandbox clone and every default
 *           reset listener (Laravel\Octane\Testing\Fakes\FakeClient).
 *
 * It then runs state-leak checks in octane mode: requests by different
 * users and tokens back to back, and switches flipped between requests.
 *
 * Usage: php scripts/spike-177-octane-fixtures.php, then
 *        php scripts/spike-177-octane-bench.php fpm|octane [iterations]
 * Each mode runs in its own process: fresh Applications built in the same
 * process as an Octane worker would take over its container and facades.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;

$base = dirname(__DIR__);
require __DIR__.'/spike-177-env.php';
require $base.'/vendor/autoload.php';

$mode = $argv[1] ?? 'octane';
$iterations = (int) ($argv[2] ?? 200);
$fixtures = json_decode(file_get_contents($base.'/storage/spike-177-fixtures.json'), true);

/** One request of the scenario, fresh each time (Request objects are mutable). */
function scenario(array $f): array
{
    return [
        'GET /up' => fn () => Request::create('/up'),
        'GET / (guest)' => fn () => Request::create('/'),
        'GET /vote (viewer)' => fn () => Request::create('/vote', 'GET', [], [$f['session_cookie'] => $f['viewer_cookie']]),
        'GET /api/agent/queue' => fn () => Request::create('/api/agent/queue', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['agent_token'], 'HTTP_ACCEPT' => 'application/json']),
        'GET /bus/{game}/actions' => fn () => Request::create('/bus/orkestera/actions?after=0', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['adapter_token'], 'HTTP_ACCEPT' => 'application/json']),
    ];
}

function stats(array $ms): array
{
    sort($ms);
    $n = count($ms);
    $pick = fn (float $p) => $ms[(int) min($n - 1, floor($p * ($n - 1)))];

    return ['mean' => array_sum($ms) / $n, 'p50' => $pick(0.5), 'p95' => $pick(0.95)];
}

// --- fpm: bootstrap per request ------------------------------------------------

function fpmHandle(string $base, Request $request): int
{
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();

    // The next "process" starts clean.
    Facade::clearResolvedInstances();
    $app->flush();

    return $status;
}

// --- octane: boot once, Octane's worker per request ----------------------------

function octaneHandle(FakeWorker $worker, FakeClient $client, Request $request): int
{
    $worker->handle($request, new RequestContext(['request' => $request]));

    return array_pop($client->responses)->getStatusCode();
}

if ($mode === 'octane') {
    $client = new FakeClient([]);
    $worker = new FakeWorker(new ApplicationFactory($base), $client);
    $worker->boot();
    $handle = fn (Request $r) => octaneHandle($worker, $client, $r);
} else {
    $handle = fn (Request $r) => fpmHandle($base, $r);
}

$times = [];
$cpu = [];
$statuses = [];

/** CPU time (user + system) this process has used, in ms: robust to a busy machine. */
function cpuMs(): float
{
    $u = getrusage();

    return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000;
}

// Warm up (autoloading, compiled views, first queries).
foreach (scenario($fixtures) as $make) {
    $handle($make());
}

$memory = [];
for ($i = 0; $i < $iterations; $i++) {
    foreach (scenario($fixtures) as $name => $make) {
        $t = hrtime(true);
        $c = cpuMs();
        $statuses[$name][] = $handle($make());
        $cpu[$name][] = cpuMs() - $c;
        $times[$name][] = (hrtime(true) - $t) / 1e6;
    }
    if ($i % 100 === 0 || $i === $iterations - 1) {
        gc_collect_cycles();
        $memory[$i] = memory_get_usage(true);
    }
}

printf("mode %s, %d iterations of the scenario, %d questions in the queue\n", $mode, $iterations, $fixtures['questions']);
printf("%-24s %8s %8s %8s  %s\n", 'request', 'mean ms', 'p50', 'p95', 'statuses');
$all = [];
foreach ($times as $name => $ms) {
    $st = stats($ms);
    $all = array_merge($all, $ms);
    printf("%-24s %8.2f %8.2f %8.2f  %s\n", $name, $st['mean'], $st['p50'], $st['p95'], json_encode(array_count_values($statuses[$name])));
}
$st = stats($all);
printf("%-24s %8.2f %8.2f %8.2f\n", 'all', $st['mean'], $st['p50'], $st['p95']);
echo 'process memory (MB) by iteration: '.implode(', ', array_map(fn ($i, $m) => "{$i}: ".round($m / 1048576, 1), array_keys($memory), $memory))."\n";
file_put_contents($base."/storage/spike-177-{$mode}-{$fixtures['questions']}q.json", json_encode(['times' => $times, 'cpu' => $cpu, 'statuses' => $statuses, 'memory' => $memory]));

if ($mode !== 'octane') {
    exit(0);
}

// --- State-leak checks in octane mode -------------------------------------------

$checks = [];
$as = fn (string $cookie, string $uri) => octaneHandle($worker, $client, Request::create($uri, 'GET', [], [$fixtures['session_cookie'] => $cookie], [], ['HTTP_ACCEPT' => 'text/html']));
$token = fn (?string $bearer, string $uri) => octaneHandle($worker, $client, Request::create($uri, 'GET', [], [], [], array_filter(['HTTP_AUTHORIZATION' => $bearer ? 'Bearer '.$bearer : null, 'HTTP_ACCEPT' => 'application/json'])));

// Moderator status (once()-memoised per user) and auth do not carry over.
$checks['viewer /moderation'] = [$as($fixtures['viewer_cookie'], '/moderation'), 403];
$checks['moderator /moderation'] = [$as($fixtures['moderator_cookie'], '/moderation'), 200];
$checks['viewer /moderation again'] = [$as($fixtures['viewer_cookie'], '/moderation'), 403];
$checks['guest /moderation'] = [octaneHandle($worker, $client, Request::create('/moderation')), 302];

// Sanctum's resolved user does not carry over (it did between test requests).
$checks['agent token /api/agent/queue'] = [$token($fixtures['agent_token'], '/api/agent/queue'), 200];
$checks['no token right after'] = [$token(null, '/api/agent/queue'), 401];
$checks['kill-switch token on agent API'] = [$token($fixtures['kill_token'], '/api/agent/queue'), 403];

// The kill switch is read fresh in a long-lived worker.
$db = new PDO('sqlite:'.$fixtures['database']);
$db->exec("update bus_controls set killed_at = datetime('now') where scope = '*'");
$checks['agent after kill (other process)'] = [$token($fixtures['agent_token'], '/api/agent/queue'), 423];
$db->exec("update bus_controls set killed_at = null where scope = '*'");
$checks['agent after restore'] = [$token($fixtures['agent_token'], '/api/agent/queue'), 200];

// A ban landing mid-life of the worker takes effect on that user's next
// request (EnsureNotBanned logs them out: a redirect), and only theirs.
$checks['soon-banned /vote before the ban'] = [$as($fixtures['banned_cookie'], '/vote'), 200];
$db->exec("insert into user_bans (user_id, reason, created_at, updated_at) values ({$fixtures['banned_id']}, 'spike', datetime('now'), datetime('now'))");
$checks['banned /vote after a local ban'] = [$as($fixtures['banned_cookie'], '/vote'), 302];
$checks['viewer /vote right after'] = [$as($fixtures['viewer_cookie'], '/vote'), 200];

echo "\nstate-leak checks (octane worker, back to back):\n";
$failed = 0;
foreach ($checks as $name => [$got, $want]) {
    $ok = $got === $want;
    $failed += $ok ? 0 : 1;
    printf("  %-36s got %d want %d  %s\n", $name, $got, $want, $ok ? 'ok' : 'LEAK');
}
echo $failed === 0 ? "all state-leak checks passed\n" : "{$failed} state-leak check(s) FAILED\n";
echo 'client errors: '.count($client->errors)."\n";
foreach (array_slice($client->errors, 0, 3) as $e) {
    echo '  '.substr($e, 0, 300)."\n";
}
