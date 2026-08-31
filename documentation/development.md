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

### Attendance kiosk

The unattended terminal is a second standalone SPA, also outside `composer dev`:

```bash
npm run dev:kiosk        # https://localhost:5175
```

Same HTTPS reason as the portal, but for the camera rather than geolocation:
`getUserMedia` is only granted on a secure origin. It proxies `/api` to port
8080 with the same caveat as the portal.

Three things must be true before it will scan, and none of them are in `.env` —
see [Attendance configuration](#4-attendance-configuration) for the first two:

1. the kiosk feature toggle is on,
2. a device token has been issued and pasted into the terminal's pairing screen,
3. the face service is reachable.

The kiosk fails closed on the third: with the service down it shows *"Layanan
pengenalan wajah sedang tidak tersedia"* and refuses to capture, rather than
letting somebody pose for a scan that was never going to resolve.

> **Testing from another machine is the one case that bites.** Opening the
> terminal over `http://<lan-ip>:5175` blocks the camera silently — no error
> dialog, just a preview that never starts. It must be `https://`, and the
> self-signed certificate has to be accepted for that exact origin. The same
> applies to the dashboard if you want to enrol a face from a second machine:
> put it behind TLS too, or enrol from `localhost`, which browsers already
> treat as secure.

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
- **Shifts** — a shift carries its own office hours, late threshold, grace
  period and optional break window. `php artisan db:seed` creates them via the
  demo data; on a hand-built database, create one under *Shifts* first.

A shift is a **layer over** the office hours above, not a replacement for them.
The schedule for one employee on one date resolves in three steps, stopping at
the first hit:

| Step | Source | Wins when |
| --- | --- | --- |
| 1 | `employee_schedules` | a row exists for that employee and date |
| 2 | `employees.shift_id` | the employee has a default shift |
| 3 | *Attendance Settings* | always — the floor |

Switching **Mode Shift** off under *Pengaturan Fitur* skips steps 1 and 2
entirely, even where the data exists. That is why the global hours stay editable
while shifts are in use: anyone without a shift still lands on them.
`AttendanceSettings::scheduleFor()` is the single resolver.

Step 1 has no admin UI. The `employee_schedules` table, its model and
`Employee::shiftForDate()` all work, but nothing writes to it yet — per-date
rotation currently means inserting rows by hand.

GPS integrity thresholds (`GPS_MAX_ACCURACY_METERS`, `GPS_MAX_AGE_SECONDS`) do
live in `.env`. Raise them if a desktop browser's coarse location is being
rejected during testing.

### Salary deductions

Attendance-driven deductions — arriving late, leaving early, overstaying a break
and absence — are configured under *Potongan Gaji*. Nothing here lives in
`.env`, and every rule ships off: a fresh installation deducts nothing.

The first three are ladders of `{from_minutes, amount}` rungs, and only the
deepest rung reached applies. With 15 → 15.000 and 30 → 40.000, forty-five
minutes late costs 40.000, **not** 55.000. Absence is a flat amount per day;
approved leave never reaches it, because `attendance:mark-absentees` skips
employees whose leave covers the date, so an `absent` row means nobody accounted
for the day at all.

Rules resolve the same way schedules do, one level shorter:

| Step | Source | Wins when |
| --- | --- | --- |
| 1 | `shifts.deduction_rules` | the shift resolved for that date overrides |
| 2 | `settings.payroll_deductions` | everyone else, and everyone while shift mode is off |

`null` in `shifts.deduction_rules` is the whole signal for "follow the global
rules", so dropping an override clears the column rather than storing a disabled
copy — otherwise that shift would silently freeze at the old values whenever the
global ladders changed. `PayrollDeductionSettings::forEmployee()` resolves both
levels; the minutes are counted against `scheduleFor()`, so a 22:00 shift is
graded against 22:00 rather than the office's 08:00.

The break rule is hidden wherever no break is ever recorded — the global break
feature being off, or that shift's `break_enabled` being false — because there
would be no overrun to measure.

**Not yet wired into payroll.** The rules are stored, validated and resolvable,
and `PayrollDeductionSettings` has the helpers to price them, but drafting a
payslip does not read them yet.

### Kiosk terminal

Off by default. Switch **Terminal Absensi (Kiosk)** on under *Pengaturan Fitur*,
then issue a token for each physical terminal:

```bash
php artisan kiosk:register "Lobi Utama" --location="Lantai 1"
```

The token is printed once and stored only as a SHA-256 hash, so a lost one is
re-issued rather than recovered. Paste it into the terminal's pairing screen; it
lives in that browser's `localStorage` and travels as an `X-Kiosk-Token` header
— never in the URL, where it would be visible on a screen strangers stand in
front of and recorded in nginx's access log.

`--ip=<address-or-CIDR>` restricts a terminal to a network, and is the location
control for the kiosk. **GPS is not used on this path at all**: a tablet bolted
to a wall has no satellite receiver, so its browser reports a coarse Wi-Fi or
IP-derived position that the accuracy threshold rejects every time. The
allowlist lives in the `kiosk_devices` table and can be changed without a
redeploy, so leaving it empty until the office address is known is fine.

The terminal identifies against the whole roster (1:N) rather than confirming an
already-authenticated employee (1:1), so it uses its own, stricter thresholds:
`KIOSK_IDENTIFY_THRESHOLD` and `KIOSK_IDENTIFY_MARGIN`. The margin is how much
closer the best match must be than the runner-up before a name is committed —
below it the scan is refused as ambiguous rather than guessed. **Enrol at least
two faces when testing**; with only one there is no runner-up and that guard is
never exercised.

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
resources/js/             Dashboard — Inertia pages, built by the root Vite config
frontend/apps/employee/   Employee portal — standalone SPA
frontend/apps/kiosk/      Attendance terminal — standalone SPA
frontend/packages/shared/ Code shared between all three
```

npm workspaces link them, so a single `npm install` at the root covers
everything. Build them separately:

```bash
npm run build            # dashboard -> public/build
npm run build:employee   # portal    -> frontend/apps/employee/dist
npm run build:kiosk      # kiosk     -> frontend/apps/kiosk/dist
```

`frontend/packages/shared/src/face.ts` holds the camera and passive-liveness
helpers, and all three surfaces that use a camera — dashboard enrolment, portal
check-in, kiosk — go through it rather than each rolling their own. Its
MediaPipe model is fetched from a CDN on first use, so a browser with no
internet access will show a preview whose face guide never activates.

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
| Kiosk camera never starts | The origin is not secure. Use `https://`, and accept the certificate for that exact host — `http://<lan-ip>:5175` fails silently. |
| Kiosk returns 404 on every endpoint | The kiosk feature toggle is off. A disabled module is made to look like it never existed. |
| Kiosk returns 401 | No token, or one that was never issued. Re-run `php artisan kiosk:register`. |
| Kiosk returns 403 | The device has an IP allowlist that the request address does not match. |
| Kiosk face guide never turns green | MediaPipe's model could not be fetched from the CDN. Check the Network tab. |
| Asset URLs lose their port behind a TLS proxy | The proxy is passing nginx's `$host`, which strips the port. Pass `$http_host` instead, or Laravel builds `https://host/build/...` and the browser reports it as a CORS failure. |
| Assets 404 or are blocked from `http://localhost:5173` when browsing from another machine | `public/hot` exists, so Laravel points every asset at the dev server. `localhost` is the *viewer's* machine, not the server's — and an HTTPS page cannot load HTTP modules anyway. Stop `npm run dev` (it removes the file) and `npm run build`. |
