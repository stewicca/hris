# HRIS Documentation

| Document | Read it when |
| --- | --- |
| [development.md](development.md) | Setting up a local machine to work on the code. |
| [production-deployment.md](production-deployment.md) | Deploying to the production VPS for the first time, or updating it. |

## What this application is

A HRIS with four deployable pieces:

| Piece | Technology | Served as |
| --- | --- | --- |
| Admin dashboard | Laravel 12 + Inertia + React 19 | Server-rendered routes, assets bundled by Vite |
| Employee portal | React 19 SPA (`frontend/apps/employee`) | Static files, talks to `/api` on the same origin |
| Attendance kiosk | React 19 SPA (`frontend/apps/kiosk`) | Static files on their own hostname; only `/api/kiosk` is proxied there |
| Face recognition | Python + FastAPI + InsightFace ArcFace | Internal HTTP service, never exposed publicly |

Backed by MySQL 8.4. A Redis container is provisioned, but cache, sessions and
queues currently all use the `database` driver, so nothing depends on it yet.

The Laravel app owns all authentication; the face service is trusted only
because it sits on the private container network and is never published.

The kiosk is optional and disabled by default. It is the one surface that
records attendance without an employee session: the face answers *who*, and a
per-device token answers *which terminal*. Because of that it gets its own
hostname and its own vhost, which proxies `/api/kiosk` and nothing else — the
login endpoint and the authenticated API are unreachable from it.
