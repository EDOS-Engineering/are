<?php

/*
 * Spike #177 fixtures (not for main): seeds the scratch database the bench
 * uses, and writes the sessions and tokens it needs to
 * storage/spike-177-fixtures.json.
 */

use App\ControlBus\ControlBus;
use App\Models\Agent;
use App\Models\BusAdapterToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

$base = dirname(__DIR__);
require __DIR__.'/spike-177-env.php';
require $base.'/vendor/autoload.php';

@unlink($base.'/storage/spike-177.sqlite');
touch($base.'/storage/spike-177.sqlite');
foreach (glob($base.'/bootstrap/cache/{config,routes-v7,events}.php', GLOB_BRACE) as $cached) {
    unlink($cached);
}

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);

$viewer = User::factory()->create(['name' => 'Spike Viewer']);
$banned = User::factory()->create(['name' => 'Spike Soon Banned']);
$moderator = User::factory()->create(['name' => 'Spike Moderator']);
TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $moderator->twitch_id]);

// The queue: `php scripts/spike-177-octane-fixtures.php [questions]`,
// with five voters per question.
$count = max(1, (int) ($argv[1] ?? 10));
Topic::set('Songs about the sea');
$voters = User::factory()->count($count * 5)->create();
$questions = Question::factory()->count($count)->create();
foreach ($voters as $i => $voter) {
    $questions[$i % $count]->recordVote($voter, $i % 7 === 0 ? -1 : 1);
}

app(ControlBus::class)->setActiveGame($moderator, 'orkestera');

$cookieName = config('session.cookie');
$sessionFor = function (User $user) use ($cookieName): string {
    // A fresh store each time: DatabaseSessionHandler remembers that the
    // last id existed, and would UPDATE (not insert) the next one.
    app('session')->forgetDrivers();
    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    $session->put(Auth::guard('web')->getName(), $user->id);
    $session->save();

    return Crypt::encrypt(CookieValuePrefix::create($cookieName, Crypt::getKey()).$session->getId(), false);
};

$agent = Agent::named('spike-agent');

file_put_contents($base.'/storage/spike-177-fixtures.json', json_encode([
    'database' => config('database.connections.sqlite.database'),
    'questions' => $count,
    'session_cookie' => $cookieName,
    'viewer_id' => $viewer->id,
    'banned_id' => $banned->id,
    'viewer_cookie' => $sessionFor($viewer),
    'banned_cookie' => $sessionFor($banned),
    'moderator_cookie' => $sessionFor($moderator),
    'agent_token' => $agent->createToken('agent', Agent::ABILITIES, now()->addDay())->plainTextToken,
    'kill_token' => $moderator->createToken('kill-switch', ['kill-switch'])->plainTextToken,
    'adapter_token' => BusAdapterToken::issue('orkestera'),
], JSON_PRETTY_PRINT));

// Cache config and routes, as `php artisan optimize` does in production.
$app->make(Kernel::class)->call('optimize');
echo "fixtures written, config and routes cached\n";
