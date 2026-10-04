[![Laravel Forge Site Deployment Status](https://img.shields.io/endpoint?url=https%3A%2F%2Fforge.laravel.com%2Fsite-badges%2F47b560ae-a754-4c28-bea0-92362c9a3ec6&style=plastic)](https://forge.laravel.com/jeremy-anderson-okr/bright-viper/2088775)

# Applied Research Equity

The investment we make in ourselves

## What even is this?

This is a Twitch app built with Laravel

### I totally stole it from

https://github.com/ThePrimeagen/topshelf-fm

@ThePrimeagen and @teej_dv and Taylor Otwell built a little twitch app on stream one day, using Laravel, for asking questions of Top Shelf guests, and I'm repurposing to fulfill my own twisted desires at https://twitch.tv/jeremyboise

This project has no affiliation with, nor is it endorsed by Laravel, or anyone else, especially me.

#### Do not use this code if you:

- value your own time
- have any self-respect
- are comfortable speaking on stage
- genuinely enjoy kale
- can change a tire

## Running locally, for fools and legends only

```sh
cp .env.example .env        # then fill in the TWITCH_* values
composer install && npm install
php artisan key:generate
touch database/database.sqlite && php artisan migrate
composer run dev            # or: php artisan serve + npm run dev
./vendor/bin/pest           # tests
```

## Twitch setup

1. In the Twitch developer console, register **both** callback URLs: `TWITCH_REDIRECT_URL` (viewer login) and `TWITCH_BROADCASTER_REDIRECT_URL` (channel connection).
2. Set `TWITCH_CHANNEL_ID` to the primary channel. List any other channels this app serves in `TWITCH_BROADCASTER_IDS`.
3. Each broadcaster logs in, then visits `/twitch/broadcaster/connect` once. This grants `moderation:read`, `channel:moderate` and `channel:manage:broadcast`, stores an encrypted token, and syncs bans and moderators.
4. Run `php artisan twitch:generate-event-sub-key`, deploy, then run `php artisan twitch:eventsub-subscribe`. Twitch then pushes ban, unban and moderator changes to `/twitch/eventsub`.
5. Run the scheduler (`php artisan schedule:work`, or a Forge scheduler job). `twitch:sync-moderation` runs hourly to catch anything EventSub missed.

| Command | What it does |
|---|---|
| `twitch:title "..." [--broadcaster=ID]` | Sets the stream title through Helix |
| `twitch:sync-moderation` | Pulls bans and moderators for every connected channel |
| `twitch:eventsub-subscribe` | Creates the EventSub webhook subscriptions |
| `twitch:generate-event-sub-key` | Writes `TWITCH_HELIX_EVENTSUB_SECRET` to `.env` |
| `short-link:create /about#work-with-us --campaign=<stream> [--source=twitch] [--medium=stream] [--content=overlay] [--code=ork]` | Creates a UTM-tagged short link served at `/go/{code}`. Enquiries from `/about` record the last link clicked |

Moderators of any served channel get the same admin powers as the broadcaster. Anyone banned or timed out on a served channel can't log in, submit or vote until the ban lifts.
## OBS overlays

Each overlay is a transparent page for an OBS browser source, at `/overlay/{name}` where the name is `queue`, `vote`, `top-vote`, `now-playing`, `captions`, `visualizer` or `cta`. Add `?layout=horizontal` for a 1920×1080 source or `?layout=vertical` for a 1080×1920 one.

Every overlay has its own token. `php artisan overlay:token queue` prints the horizontal and vertical URLs once. Only a hash is stored, so the URL can't be shown again. To replace a leaked URL, run `php artisan overlay:token queue --rotate`; the old URL stops working, and any open copy goes blank on its next refresh. The queue, vote and top-vote overlays refresh every 5 seconds.

The `cta` lower-third rotates between the Orkestera and EDOS Professional Services calls to action. Set `ARE_CTA_ORKESTERA_URL` and `ARE_CTA_EDOS_URL`: an item with no URL is not shown. The copy lives in `config/are.php`.

**Upgrading from `/top-vote`.** `/top-vote` used to be public. It now redirects permanently to `/overlay/top-vote`, which needs a token, so an existing `/top-vote` source shows nothing (its request gets a 403). OBS caches the redirect. Replace the source URL with the one printed by `php artisan overlay:token top-vote`.

**Where overlay tokens end up.** The token travels in the URL's query string, because OBS browser sources can't send headers. So:

- **The web server's access logs contain overlay URLs, tokens included.** On Forge that means nginx's access log, which records every OBS source load. Anyone who can read those logs can open the overlays. If a log leaks, rotate the affected tokens. To keep tokens out of the log, give the `/overlay/` location a log format that records `$uri` (the path without its query) instead of `$request`, for example `log_format no_query '$remote_addr - $remote_user [$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent';` and `access_log /var/log/nginx/<site>-access.log no_query;` inside `location /overlay/ { ... }`. This snippet hasn't been tried on our Forge server yet.
- **Sentry never receives them.** `App\Support\SentryScrubber`, set as `before_send`, `before_send_transaction` and `before_breadcrumb` in `config/sentry.php`, replaces every `token=` value with `[Filtered]`. Sentry would otherwise attach the full URL and query string to every event, whatever `SENTRY_SEND_DEFAULT_PII` says.
- **OBS stores them** in its scene collection JSON on the streaming machine.
- **Pages never pass them on.** Responses send `Referrer-Policy: no-referrer`, so a token never leaves in a `Referer` header, and `Cache-Control: no-store` keeps it out of caches.

## Forge deployment and PostgreSQL

Create the database and its owning login before deploying. For bright-viper:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=are
DB_USERNAME=are_app
# Set DB_PASSWORD separately in Forge's Environment editor.
```

Use this Forge deploy script (Forge supplies the PHP, Composer and FPM variables):

```bash
set -euo pipefail
cd /home/forge/appliedresearchequity.com
git pull --ff-only origin "$FORGE_SITE_BRANCH"
bash scripts/forge-deploy.sh
```

The checked-in script installs locked dependencies with `npm ci`, clears the old
configuration file, runs migrations **before** clearing the database cache, then
rebuilds caches, restarts queue workers and reloads PHP-FPM. The FPM reload is
required when `opcache.validate_timestamps=0`; CLI migrations alone do not refresh
the web process's cached PHP classes. A failing step stops deployment.

The forward repair migration fills missing `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches` and `failed_jobs` tables even when their original migrations
are already recorded. It checks each table independently and leaves existing
tables and rows intact. It does not repair arbitrary missing columns or rebuild
the application's migration ledger. Its rollback deliberately retains these
shared tables, so reverting code cannot destroy sessions or queued work.

For an initial database, or recovery from a deploy that stopped before migrations:

```bash
php8.3 artisan config:clear
php8.3 artisan migrate --force
php8.3 artisan cache:clear
```

Do not run `migrate:fresh` or `migrate:reset` in production. Creating the schema
does not transfer users, questions or votes from a previous database. The tests
cover the full migration chain, runtime-table repairs and voting on SQLite and
PostgreSQL 14, including a clean-database deployment preparation and retry.

## Production queues (Forge)

Production runs its queues on Redis under [Horizon](https://laravel.com/docs/12.x/horizon). Local development and tests keep `QUEUE_CONNECTION=database` and `sync`, so you don't need Redis on your machine. Horizon runs two supervisors (`config/horizon.php`):

- `supervisor-broadcasts` works the `broadcasts` queue and always keeps at least one worker, so vote updates never wait behind EventSub jobs.
- `supervisor-default` works the `default` queue.

Every supervisor `timeout` stays below the redis connection's `retry_after` (`REDIS_QUEUE_RETRY_AFTER`, 90 seconds by default). A test enforces this.

**The production Redis is shared** with other apps on this server (gemreptiles, 12thfret), which use the low database indexes. ARE keeps to its own indexes and key prefixes, whatever `APP_NAME` is:

| What | Redis connection | Index | Key prefix |
|---|---|---|---|
| Queue, Horizon, cache locks | `default` | `REDIS_DB`, 4 by default | `are_database_`, and Horizon's own `are_horizon:` |
| Cache | `cache` | `REDIS_CACHE_DB`, 5 by default | `are_database_` + `are_cache_` |

`scripts/forge-deploy.sh` runs `cache:clear`. With `CACHE_STORE=redis` that is a `FLUSHDB` on `REDIS_CACHE_DB` only. It never touches `REDIS_DB` or another app's index, and a test pins this. So `REDIS_CACHE_DB` must be an index nothing else uses: never `REDIS_DB`, and never 0 or 1.

`/horizon` is open to everyone in `local`. Elsewhere only the broadcasters of served channels (`TWITCH_CHANNEL_ID` and `TWITCH_BROADCASTER_IDS`) who are not banned can open it. Everyone else gets a 403, including moderators. The dashboard shows every job's payload and can retry or delete failed jobs, so it is an operator tool, not a moderation one.

Operator checklist:

1. **Check the server.** Under Server > Overview, confirm it is an **App** server, because only App and Cache servers come with Redis. SSH in and check `redis-cli ping` (expect `PONG`), `php -m | grep -i redis` and `ulimit -n`.
2. **Note the deploy strategy.** If the deploy script contains `$CREATE_RELEASE()`, the site uses zero-downtime deployments.
3. **Redis password and indexes.** Set `REDIS_PASSWORD` in the site environment to the server's real Redis password. Run `redis-cli -a "$REDIS_PASSWORD" INFO keyspace` and confirm `db4` and `db5` are absent (empty). If another app already uses them, pick two free indexes and set `REDIS_DB` and `REDIS_CACHE_DB` to them.
4. **Set the site environment:** `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_DB=4`, `REDIS_CACHE_DB=5` (or the indexes from step 3) and `REDIS_CLIENT`. Use `phpredis` if step 1 listed the `redis` extension, otherwise `predis`. Set `APP_ENV=production` to get the production worker counts. Any other non-`local` value (a staging site, say) falls back to the smaller `*` supervisors in `config/horizon.php`.
5. **Turn on the "Laravel Horizon" toggle** on the site's Overview tab. Then delete any plain queue workers for the site, because Forge says not to run them alongside Horizon. Make sure the **Laravel Scheduler** toggle is on: it runs `horizon:snapshot` every five minutes for the metrics, as well as `twitch:sync-moderation`.
6. **Check the deploy script restarts Horizon after the new code is live.** The Forge script above ends with `bash scripts/forge-deploy.sh`, whose `queue:restart` does not restart the Horizon master. When the Horizon toggle is on, Forge appends `$FORGE_PHP artisan horizon:terminate` to the Forge script if it is missing. Keep that line **after** `bash scripts/forge-deploy.sh`, so Horizon restarts on the new code. On a zero-downtime site, `$RESTART_QUEUES()` after `$ACTIVATE_RELEASE()` covers it instead. Alternatively, `$FORGE_PHP artisan reload` (Laravel 12.45+) runs `horizon:terminate` along with the other reloadable services, such as Reverb once it lands.
7. **Set the Horizon daemon's Stop Seconds** to at least the longest job's runtime (the longest supervisor `timeout`, 60 seconds today), so a deploy doesn't kill a job mid-run.
8. **Smoke test.** `/horizon` should load for the broadcaster and return 403 for a moderator and a viewer. The dashboard should show both supervisors running. After ten minutes, the Metrics tab should have data.
