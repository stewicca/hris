# Development Setup

There are two ways to run this locally. Pick one:

- **Native** — PHP and Node on your machine, SQLite for the database. Fastest
  to start, closest to how you will actually iterate. Recommended.
- **Containers** — the full stack via `docker-compose.yml`, including MySQL,
  Redis and face recognition. Use this when you need to exercise something the
  native path cannot, such as the face service or MySQL-specific behaviour.

---

## 1. Native setup

### Prerequisites

| Tool | Version | Note |
| --- | --- | --- |
| PHP | 8.4 | With `pdo_sqlite`, `mbstring`, `gd`, `bcmath`, `exif` |
| Composer | 2.x | |
| Node.js | 22.x | npm workspaces are used, so npm 9+ |

### First run

```bash
git clone <repo-url> hris
cd hris
composer setup
```

`composer setup` installs PHP and npm dependencies, copies `.env.example` to
`.env`, generates an `APP_KEY`, runs the migrations and builds the frontend.

The default `.env` uses SQLite (`DB_CONNECTION=sqlite`). Laravel will offer to
create `database/database.sqlite` for you; if the migration step fails because
the file is missing, create it and re-run:

```bash
touch database/database.sqlite
php artisan migrate
```

### Seed test data

```bash
php artisan db:seed
```

This creates two accounts and roughly a month of fabricated attendance, leave
and payroll records so the dashboard has something to show:

| Username | Password | Role |
| --- | --- | --- |
| `admin` | `password` | Administrator |
| `budi` | `password` | Employee (portal test account) |

`DatabaseSeeder` refuses to run when `APP_ENV=production`. See
[production-deployment.md](production-deployment.md) for what production seeds
instead.

### Run it

```bash
composer dev
```

That runs four processes together: `php artisan serve` (port 8000), the queue
worker, `php artisan pail` for logs, and Vite (port 5173). The dashboard is at
<http://localhost:8000>.

### Employee portal

The portal is a separate SPA and is **not** started by `composer dev`:

```bash
npm run dev:employee     # https://localhost:5174
```

It serves over HTTPS (via `@vitejs/plugin-basic-ssl`) because browsers only
grant the geolocation API on secure origins. Expect a self-signed certificate
warning the first time.

> **Its dev server proxies `/api` to `http://localhost:8080`, not to
> `php artisan serve` on 8000.** That is the container stack's port. To use the
> portal against a native backend, either run the container stack alongside it,
> or change the proxy target in `frontend/apps/employee/vite.config.ts`.

---

## 2. Container setup

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The dashboard is at <http://localhost:8080>. Both published ports are bound to
`127.0.0.1`, so nothing is reachable from other machines on your network.

Point `.env` at the container database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=hris
DB_USERNAME=hris
DB_PASSWORD=secret
```

Services in the stack: `app` (PHP-FPM), `nginx`, `db` (MySQL 8.4), `redis`,
`face-recognition`.

---

## 3. Face recognition

The service is heavy: the image bundles the InsightFace `buffalo_l` model pack
(~330 MB) and takes a while to build the first time. For most work you do not
need it:

```dotenv
FACE_RECOGNITION_ENABLED=false
```

With that set, attendance falls back to GPS-only and the face image becomes an
optional upload rather than a required one.

When you do need it, it runs at `http://face-recognition:5000` inside the
compose network and exposes `/health`, `/embed` and `/verify`. See
[`services/face-recognition/README.md`](../services/face-recognition/README.md).

---

## 4. Attendance configuration

Two settings decide whether check-in succeeds, and neither lives in `.env`:

- **Office geofence** — set under *Attendance Settings* in the dashboard and
  stored in the `settings` table. Until an admin configures it, the geofence is
  inactive and check-in works from anywhere. That is usually what you want
  locally.
- **Shifts** — employees reference a shift for their office hours, late
  threshold and grace period. `php artisan db:seed` creates them via the demo
  data; on a hand-built database, create one under *Shifts* first.

GPS integrity thresholds (`GPS_MAX_ACCURACY_METERS`, `GPS_MAX_AGE_SECONDS`) do
live in `.env`. Raise them if a desktop browser's coarse location is being
rejected during testing.

---

## 5. Tests and checks

```bash
php artisan test --compact                                  # everything
php artisan test --compact --filter=AttendanceTest          # one file or name
composer ci:check                                           # everything, strictly
```

`composer ci:check` is the strictest gate: ESLint, Prettier and `tsc --noEmit`
in check-only mode, then Pint and the full suite. CI is slightly different — it
runs Pint, Prettier and ESLint in *fixing* mode and does not type-check — so
`ci:check` passing locally implies CI passes, but not the reverse.

CI runs `npm run build` before the tests. Locally, if you have never built the
frontend, roughly 44 tests fail with `Vite manifest not found`. That is a
missing build, not a broken test — build once and they pass.

Individual tools:

```bash
vendor/bin/pint --dirty      # format changed PHP
npm run lint                 # ESLint with --fix
npm run format               # Prettier over resources/
npm run types:check          # TypeScript
```

Tests run against in-memory SQLite (see `phpunit.xml`); your `.env` database is
never touched.

> **Every change needs a test.** Write or update one, then run the affected
> file. Feature tests under `tests/Feature/` are the default; reach for
> `tests/Unit/` only for logic with no framework involvement.

---

## 6. Frontend layout

```
resources/js/            Dashboard — Inertia pages, built by the root Vite config
frontend/apps/employee/  Employee portal — standalone SPA
frontend/packages/shared/ Code shared between the two
```

npm workspaces link them, so a single `npm install` at the root covers all
three. Build them separately:

```bash
npm run build            # dashboard -> public/build
npm run build:employee   # portal    -> frontend/apps/employee/dist
```

### Wayfinder

Route helpers imported from `@/actions` and `@/routes` are generated, not
written by hand. The Vite plugin regenerates them on every build and dev-server
start; to do it manually:

```bash
php artisan wayfinder:generate
```

The generated directories are gitignored. If TypeScript complains that
`@/actions/...` does not exist, run the command above or start the dev server.

---

## 7. Common problems

| Symptom | Cause |
| --- | --- |
| `Vite manifest not found` on any page | Frontend was never built. Run `npm run build`, or `npm run dev` for an active dev server. Note this also fails the ~44 Inertia tests. |
| `Unable to locate file in Vite manifest` | Same cause. |
| Portal shows network errors on `/api` | Its proxy targets port 8080. Start the container stack or retarget the proxy. |
| `@/actions/...` not found in TypeScript | Wayfinder output missing — see above. |
| Geolocation blocked in the portal | Needs HTTPS. `npm run dev:employee` already serves TLS; accept the self-signed certificate. |
| Frontend change not visible | The build is stale. Run `npm run build` or keep `npm run dev` running. |
