# media-api

JSON API for the Media02 home media server. Laravel, no database: it reads the
filesystem and runs shell commands (get-iplayer, yt-dlp, ssh to media01, …).
The React frontend lives in [`media-app`](https://github.com/asprinwizard/media-app).

Plan and design: `.documentation/media-site/development-plan.md` in the
workspace (outside this repo).

## How auth works

Apache Basic auth (`.htpasswd`) guards every request. Laravel trusts only
`REMOTE_USER` (set by Apache after checking the password) and reads roles
(`admin`, `ftp`, `standard`) from the same `.htgroup` file Apache uses.

- `media.user` middleware: resolves the user, 401 if Apache didn't authenticate.
- `role:admin` / `role:admin,ftp`: per-route role checks (403).
- `same-origin`: state-changing requests must be same-origin and JSON (or send
  `X-Requested-With`), which blocks CSRF via cached Basic-auth credentials.

## Local development

```sh
composer install
cp .env.example .env && php artisan key:generate
sh dev/make-sample-library.sh        # fake library under storage/dev/
```

Paste the `MEDIA_*` lines the script prints into `.env`, then add:

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
MEDIA_DEV_AUTH=true
MEDIA_DEV_USER=richard
MEDIA_DEV_ROLES=admin,ftp,standard
```

`php artisan serve` → `curl localhost:8000/me`. For playing and downloading
files locally also run `php -S 127.0.0.1:8001 dev/static-server.php`, which
stands in for Apache's `/media` and `/shared`. (Routes have no `/api` prefix: in production Apache serves the app under `Alias /api`; in development the Vite dev server proxies `/api/*` to `localhost:8000/*`.) The dev login only works
with `APP_ENV=local`, `MEDIA_DEV_AUTH=true` **and** a request from localhost.

## Tests

```sh
./vendor/bin/pest
./vendor/bin/pint --test
```

## Code map

| Path | What |
|---|---|
| `config/media.php` | All paths, servers, menu and folder rules (from `.env`) |
| `app/Auth` | `MediaUser`, `HtgroupRepository` |
| `app/Http/Middleware` | `ResolveHtUser`, `RequireRole`, `RequireSameOrigin` |
| `app/Support/Paths` | `MediaPath` + `MediaPathResolver`: every client path goes through these |
| `app/Support/Shell` | `LocalShell` (argv arrays, background jobs), `RemoteShell` (ssh) |
| `deploy/` | Apache vhost template |

## Server facts

media02: Raspberry Pi, PHP 8.4 **32-bit**, mod_php. PHP can't size or stream
files over 2 GB on 32-bit, so sizes come from `find`/`stat`, and **PHP never
sends media**: downloads redirect to Apache's `/media/…?download`, and
Chromecast links are symlink folders Apache serves at `/shared/…`.
(mod_xsendfile is not used: it fails with "unable to stat file" on media02's
drives.)

iPlayer: downloads use the get-iplayer snap (`sudo get-iplayer`), but the
`--info` lookups that give the page its titles and expected sizes use a
patched copy, because get_iplayer 3.36's `--info` dies with "Illegal division
by zero" on some programmes. Install it with `sudo sh
scripts/install_get_iplayer_info` and set
`MEDIA_IPLAYER_INFO_COMMAND=/usr/local/bin/get_iplayer-info`. Lookups are
logged to `get_iplayer_file_info.log`.
