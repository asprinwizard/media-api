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

`php artisan serve` → `curl localhost:8000/me`. (Routes have no `/api` prefix: in production Apache serves the app under `Alias /api`; in development the Vite dev server proxies `/api/*` to `localhost:8000/*`.) The dev login only works
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

media02: Raspberry Pi, PHP 8.4 **32-bit**, mod_php, mod_xsendfile. PHP can't
size or stream files over 2 GB on 32-bit, so file sizes come from `stat` and
all file delivery goes through X-Sendfile.
