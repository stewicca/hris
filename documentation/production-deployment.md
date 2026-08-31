# Production Deployment

This guide uses placeholders throughout. Substitute your own before running
anything:

| Placeholder | Meaning | Example |
| --- | --- | --- |
| `<server>` | SSH alias or `user@host` for the production box | `hris-prod` |
| `<server-ip>` | Its public IPv4 address | `203.0.113.10` |
| `<dashboard-host>` | Admin dashboard hostname | `hris.example.com` |
| `<portal-host>` | Employee portal hostname | `portal.example.com` |
| `<repo-url>` | Git remote to deploy from | `git@github.com:acme/hris.git` |

Reference environment this was written against: a small VPS running Ubuntu
24.04, 2 vCPU / 4 GB, with a key-only `deploy` user in the `sudo` group and UFW
allowing 22/80/443. Nothing below depends on a specific provider.

Architecture:

```
Internet :80 :443
      |
   Caddy (on the host, via apt — terminates TLS)
      |
   127.0.0.1:8080
      |
   [ nginx ] --- serves the employee SPA, routes both vhosts
      |
   [ app: PHP-FPM + supervisor ]  <--->  [ face-recognition ]
      |
   [ db: MySQL ]   [ redis ]          (no published ports)
```

Caddy runs on the host, not in a container, so Let's Encrypt certificates
survive image rebuilds and it keeps answering while the app stack restarts.

---

## Part 1 — First deploy

### Step 1. Server prerequisites

SSH in as `deploy` and install Docker and Caddy:

```bash
ssh <server>

# Docker Engine + compose plugin
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker deploy
newgrp docker        # or log out and back in

# Caddy
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
```

**Cap the Docker logs.** The default `json-file` driver grows without bound and
is a common cause of a full disk:

```bash
sudo tee /etc/docker/daemon.json >/dev/null <<'JSON'
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "3" } }
JSON
sudo systemctl restart docker
```

Confirm the firewall allows only what it should:

```bash
sudo ufw status verbose      # expect: deny incoming, allow 22/80/443
```

> **Docker bypasses UFW.** It writes its own nat rules ahead of UFW's, so any
> `ports:` entry without a `127.0.0.1:` prefix is published to the whole
> internet regardless of what `ufw status` says. Every published port in
> `docker-compose.prod.yml` is already loopback-bound. Keep it that way.

### Step 2. DNS

Point the names at the server before requesting certificates — Caddy needs to
answer the ACME challenge on a name that already resolves:

```
<dashboard-host>.   A   <server-ip>
<portal-host>.      A   <server-ip>
<kiosk-host>.       A   <server-ip>     # only if deploying a terminal
```

Verify: `dig +short <dashboard-host>`

The kiosk name is optional. Skip it and leave `HRIS_KIOSK_HOST` unset in
step 4; its vhost is then bound to a name nothing resolves to and matches
nothing.

### Step 3. Caddy

```bash
sudo tee /etc/caddy/Caddyfile >/dev/null <<'CADDY'
<dashboard-host>, <portal-host>, <kiosk-host> {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
CADDY

sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

All names go to the same upstream on purpose: the nginx container routes them
apart by `server_name`, so Caddy must preserve the `Host` header — which
`reverse_proxy` does by default. Drop `<kiosk-host>` from the line if you are
not deploying a terminal; Caddy will otherwise fail to get a certificate for a
name that does not resolve. Caddy also sets `X-Forwarded-Proto`, which
`docker/nginx.prod.conf` passes through to Laravel so it generates `https://`
asset URLs instead of `http://`.

**`X-Forwarded-For` is load-bearing.** Caddy appends the real client address to
it, and everything downstream derives the client IP from that: nginx's
`limit_req` buckets and Laravel's rate limiters both key on it. nginx reads the
*last* entry (`real_ip_recursive off`), which is the one Caddy appended, so a
client cannot spoof its own value to win a fresh bucket. Do not add a
`header_up X-Forwarded-For` override that discards Caddy's own value.

### Step 4. Clone and configure

```bash
git clone <repo-url> ~/hris
cd ~/hris

cp .env.prod.example .env.prod
chmod 600 .env.prod
```

Generate an application key. Laravel's `key:generate` writes to `.env`, not
`.env.prod`, so produce one and paste it in by hand:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Now edit `.env.prod` and replace **every** `CHANGE_ME`:

| Key | Notes |
| --- | --- |
| `APP_KEY` | The value generated above. |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Distinct, long, random. Store in the password manager. |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | The bootstrap admin. The seeder aborts if either is missing. |
| `HRIS_DASHBOARD_HOST`, `HRIS_PORTAL_HOST` | The two hostnames from step 2. These are rendered into the nginx container's `server_name` at start, so the domains live here rather than in the image — no repo file needs editing per deployment. |
| `HRIS_KIOSK_HOST` | Optional, and commented out in the example. Set it only if you are deploying an attendance terminal. |
| `APP_URL` | `https://<dashboard-host>` |

Also confirm `APP_TIMEZONE`. It defaults to `Asia/Jakarta` in the example, but
`config/app.php` falls back to `Asia/Makassar` if the key is absent — a one-hour
difference that silently corrupts every late-arrival calculation.

### Step 5. Build the images

**Build on your laptop or in CI, never on the server.** The build runs both
Vite builds; rollup and esbuild on a small VPS are slow at best and get
OOM-killed at worst.

On your laptop:

```bash
./build-prod.sh --save
scp hris-images.tar.gz <server>:~/hris/
```

If you have a registry, use it instead — it makes updates a single `--pull`:

```bash
./build-prod.sh --push ghcr.io/<owner>/hris
```

and add the matching prefix to `.env.prod` on the server:

```dotenv
IMAGE_PREFIX=ghcr.io/<owner>/hris
```

### Step 6. Start the stack

```bash
cd ~/hris
gunzip -c hris-images.tar.gz | docker load        # skip if using a registry
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

> Always pass `--env-file .env.prod`. Compose resolves `${...}` from `.env` in
> the project directory, not from the `.env.prod` mounted into the app
> container. Without it the database would be created with one password while
> Laravel authenticates with another.

The app container's entrypoint runs the migrations, clears stale caches and
re-caches config, views and events on every start. There is no separate migrate
step. Watch it:

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml logs -f app
```

### Step 7. Seed the bootstrap data

Once, on the first deploy only:

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml \
    exec app php artisan db:seed --class=ProductionSeeder --force
```

`ProductionSeeder` creates exactly two things: the administrator from
`ADMIN_EMAIL`/`ADMIN_PASSWORD`, and one active default shift. No employees, no
attendance, no fabricated records.

**Do not run `php artisan db:seed` without `--class`.** The default
`DatabaseSeeder` seeds demo data and will refuse to run in production, but the
habit is worth avoiding.

### Step 8. Verify

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
curl -I https://<dashboard-host>/up
curl -I https://<portal-host>/
curl -I https://<kiosk-host>/            # only if deployed
```

`app` and `nginx` should report `healthy`. `face-recognition` loads a large
model and may take a couple of minutes to get there.

---

## Part 2 — Post-deploy checklist

The application is running but not yet usable. Log in at
`https://<dashboard-host>` as the admin and work through these:

- [ ] **Change the admin password.** `ADMIN_PASSWORD` has been sitting in a
      file; treat it as a one-time credential.
- [ ] **Set the office geofence** under *Attendance Settings*. **Until you do,
      the geofence is inactive and employees can clock in from anywhere, with
      no error and nothing in the logs.** This is the single easiest thing to
      forget on this checklist.
- [ ] **Review the default shift** under *Shifts*, or create the real ones.
      Check-in time, check-out time, late threshold and grace period all come
      from here.
- [ ] **Create departments and positions**, then the employees.
- [ ] **Enrol faces** for each employee if `FACE_RECOGNITION_ENABLED=true`.
      With `FACE_REQUIRE_ENROLLMENT=true` an employee without an enrolled face
      cannot check in at all.
- [ ] **Configure real mail.** `MAIL_MAILER=log` discards everything, which
      means password reset emails are never delivered.
- [ ] **Confirm feature toggles** — leave, payroll, shifts, breaks and the kiosk
      are each switchable and hide their UI when off.

If you are deploying an attendance terminal, also:

- [ ] **Turn on *Terminal Absensi (Kiosk)*** under *Pengaturan Fitur*. It ships
      off, and every kiosk endpoint answers 404 until it is on.
- [ ] **Issue a token per terminal** and pair each one:
      `docker compose --env-file .env.prod -f docker-compose.prod.yml exec app \
      php artisan kiosk:register "Lobi Utama" --location="Lantai 1"`.
      It is printed once and stored only as a hash.
- [ ] **Restrict each terminal to the office network** once its public address
      is known: re-issue with `--ip=<address-or-CIDR>`. Until then the token
      works from anywhere, and **the kiosk does not use GPS** — a wall-mounted
      tablet has no satellite receiver, so the allowlist is the only location
      control it has. `docker/nginx.prod.conf.template` carries a commented
      `allow`/`deny` block if you would rather enforce it at the edge.
- [ ] **Enrol every employee who will use the terminal.** The kiosk identifies
      against enrolled faces only; an unenrolled employee is simply not
      recognised.
- [ ] **Watch the first day's scans.** `KIOSK_IDENTIFY_THRESHOLD` and
      `KIOSK_IDENTIFY_MARGIN` are set conservatively. Frequent "wajah tidak
      dikenali" means the threshold is too tight; any mistaken identity at all
      means it is too loose and the margin should go up. Re-enrol poor
      reference photos before touching the numbers — they matter more.

---

## Part 3 — Updating

From the repo directory on the server:

```bash
cd ~/hris
./update-prod.sh --load hris-images.tar.gz    # after scp'ing a new build
./update-prod.sh --pull                       # if using a registry
```

The script fast-forwards the checkout, warns about keys added to
`.env.prod.example` but missing from `.env.prod`, dumps the database, loads or
pulls the images, recreates the containers and waits for the health checks.
`./update-prod.sh --help` lists the flags.

Typical release cycle:

```bash
# laptop
git push
./build-prod.sh --save
scp hris-images.tar.gz <server>:~/hris/

# server
ssh <server> 'cd hris && ./update-prod.sh --load hris-images.tar.gz'
```

Expect a short outage while the app container is replaced — there is no
blue/green here, and the entrypoint runs migrations before serving traffic.

### If an update fails

`update-prod.sh` prints the failing container's logs, the previous git
revision, and the path of the pre-update backup, then stops. **It does not roll
back automatically, by design:** the entrypoint has already applied the
migrations, so reverting the images without reverting the schema leaves the
database ahead of the code — usually worse than the outage.

To recover, decide deliberately between fixing forward and restoring:

```bash
# restore the pre-update dump
gunzip -c backups/hris-<timestamp>.sql.gz | docker compose --env-file .env.prod \
    -f docker-compose.prod.yml exec -T db mysql -u root -p<DB_ROOT_PASSWORD> hris
```

---

## Part 4 — Operations

### Backups

Two scripts, split by where a dump is useful:

| Script | Runs on | Does |
| --- | --- | --- |
| `dump-db.sh` | the server | dumps the database to `backups/`, verifies it, prunes old ones |
| `pull-backups.sh` | your laptop | copies those dumps off the server and verifies the copies |

`update-prod.sh` calls `dump-db.sh` before every update and keeps the last
seven. That covers deploys, not disasters — **those dumps live on the same disk
as the database they protect**, so the second script is the one that makes them
a backup.

On the server:

```bash
ssh <server>
cd hris
./dump-db.sh              # -> backups/hris-<timestamp>.sql.gz + .sha256
./dump-db.sh --keep 30    # retention; default is 7
```

The dump is taken with `--single-transaction`, so the site stays writable. It
is rejected and deleted unless the gzip stream is intact and mysqldump wrote
its `-- Dump completed` trailer, and a `.sha256` sidecar is written next to it.

From your laptop:

```bash
export HRIS_SERVER=<server>          # or pass it as an argument

./pull-backups.sh                    # pull whatever is on the server now
./pull-backups.sh --dump             # dump on the server first, then pull
./pull-backups.sh --keep 30          # prune the local archive past 30 copies
./pull-backups.sh --verify-all       # re-verify every local copy
```

Dumps land in `~/hris-backups`, **outside this checkout on purpose**. A dump
holds every employee record and password hash in the production database, so it
must not sit in a working tree where a stray `git add -A` can pick it up — which
is also why there is no `.gitignore` entry for it, an ignore rule only hides the
file, it does not make keeping it there safe. Override the location with
`--dest` or `HRIS_BACKUP_DEST`, and keep it outside the repo:

```bash
./pull-backups.sh --dest /mnt/archive <server>     # good
./pull-backups.sh --dest ./backups <server>        # don't: inside the checkout
```

Store the archive on an encrypted disk if you have one; treat it as a copy of
production, because it is one.

Transfers are resumable and skip names already present, so re-running over a
slow link is cheap; every new copy is checked against its sidecar hash. If a
copy fails verification, re-run with `--refetch`: it compares by content and
overwrites the bad file, no manual delete needed. Nothing is ever
deleted on the server — retention there is `dump-db.sh --keep`.

Restoring is the inverse, and **wipes the target database**:

```bash
gunzip -c ~/hris-backups/hris-<timestamp>.sql.gz | docker compose --env-file .env.prod \
    -f docker-compose.prod.yml exec -T db mysql -u root -p<DB_ROOT_PASSWORD> hris
```

Scheduled off-site backups are not set up yet; the laptop-side pull is manual.
A cron entry on a machine that is not the server is the smallest next step:

```cron
30 2 * * * cd ~/hris && ./pull-backups.sh --dump --keep 30 <server> >> ~/hris-backup.log 2>&1
```

### Logs

```bash
DC="docker compose --env-file .env.prod -f docker-compose.prod.yml"

$DC logs -f app                  # PHP-FPM, queue worker, scheduler
$DC logs -f nginx
$DC logs -f face-recognition
$DC exec app tail -f storage/logs/laravel.log
sudo journalctl -u caddy -f      # TLS and proxy errors
```

### Database access from a laptop

MySQL publishes no port. Tunnel instead — and note that the port must be
published on loopback inside the compose file first if you truly need direct
access:

```bash
ssh -L 3306:127.0.0.1:3306 <server>
```

Or just use a shell:

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml \
    exec db mysql -u root -p hris
```

### Rate limiting

Two layers, deliberately:

| Layer | Scope | Limit |
| --- | --- | --- |
| nginx `limit_req` | Per IP, before PHP starts | 300 r/m app, 120 r/m `/api`, 20 r/m `/api/login` |
| Laravel `RateLimiter` | Per user, or per IP when unauthenticated | 60/min `/api`, 12/min face endpoints, 5/min per account on login plus 20/min per IP |

nginx rejects floods cheaply; Laravel enforces the per-account limits that
actually stop password guessing. Rejections appear as `429` and, at the nginx
layer, in `logs nginx` at warn level.

This stops brute force, credential stuffing and single-source abuse. It does
**not** stop a volumetric DDoS — that saturates the uplink before any of this
runs, and needs Cloudflare or equivalent in front. Not set up yet.

### Memory

The stack is sized for 2 vCPU / 4 GB with hard per-container limits:

| Container | Limit |
| --- | --- |
| `face-recognition` | 1536M |
| `app` | 640M |
| `db` | 512M |
| `nginx` | 96M |
| `redis` | 96M |

```bash
docker stats --no-stream
free -h
```

**Sustained swap use above 200 MB means it is time to upgrade.** The symptom is
not an error — it is responses quietly getting slower.

`face-recognition` is by far the heaviest, and inference competes with PHP-FPM
for CPU. The 08:00 check-in burst is the worst case. If it becomes a problem
before you can add capacity, set `FACE_RECOGNITION_ENABLED=false` to fall back
to GPS-only attendance.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `app` restart-loops on boot | Migration or `config:cache` failed. The entrypoint aborts deliberately rather than serve a half-configured app — read `logs app`. |
| Laravel authenticates but the DB rejects it | Started without `--env-file .env.prod`. |
| Assets 404 after a deploy | The `app` and `nginx` images were built from different revisions. Rebuild both; `build-prod.sh` always does. |
| nginx serves the wrong site, or 404s both hosts | `HRIS_DASHBOARD_HOST` / `HRIS_PORTAL_HOST` do not match the names Caddy forwards. They are rendered into `server_name` at container start; `docker compose ... exec nginx cat /etc/nginx/conf.d/default.conf` shows what was rendered. |
| Mixed-content warnings, `http://` asset URLs | `X-Forwarded-Proto` is not reaching Laravel. Check the Caddy config and `docker/nginx.prod.conf`. |
| Check-in accepted from anywhere | The geofence was never configured. See the post-deploy checklist. |
| Everyone rate-limited at once, or one abuser never limited | Client IP is not resolving. Every request is landing in one bucket (or a spoofed one). Check `set_real_ip_from` in `docker/nginx.prod.conf` and Caddy's `X-Forwarded-For`. |
| `api_logs` growing without bound | The scheduler is not running. `docker compose ... exec app php artisan schedule:list`. |
| Late arrivals off by an hour | `APP_TIMEZONE` missing from `.env.prod`, so the `Asia/Makassar` fallback applies. |
| Employees cannot check in at all | `FACE_REQUIRE_ENROLLMENT=true` with no enrolled face, or `face-recognition` unhealthy. |
| Disk filling up | Docker log rotation was never configured, or old images accumulated. `docker system df`. |
