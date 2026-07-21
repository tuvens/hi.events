# tickets.tuvens.com deployment (Tuvens integration)

Deployment/environment reference for running this hi.events fork as
`tickets.tuvens.com`, the ticketing leg of the Tuvens integration.
Contract of record: tuvens-api `docs/integrations/hi-events-contract.md`
(ratified revision 04469c48). Workstream: tuvens/hi.events#16.

## Zero platform fee

Organisers connect their own Stripe keys; the platform takes no cut.

| Env | Value | Effect |
|---|---|---|
| `APP_SAAS_MODE_ENABLED` | `false` (default) | Disables SaaS mode; `application_fee` is never applied to organiser payments. Config-only — verified no other fee path. |

Do not set the `APP_SAAS_STRIPE_APPLICATION_FEE_*` variables.

## Backend environment

| Env | Example | Purpose |
|---|---|---|
| `MAIN_BACKEND_URL` | `https://api.tuvens.com` | tuvens-api base URL. Used for the S2S validate call (`/api/service/hi-events/session/validate`) and as the base of the auto-registered webhook receiver (`/api/webhooks/ticketing/hi-events`). |
| `MAIN_BACKEND_SHARED_SECRET` | *(operator-provisioned)* | `HIEVENTS_S2S_SECRET` counterpart. HMAC key for both directions of the S2S seam. **Canonical home: `backend/.env` (gitignored) locally, the deploy environment in production — never a tracked file.** Rotation checkpoint: **complete** (2026-07-21); the exposed development value is burned and invalid. |
| `MAIN_BACKEND_TIMEOUT` | `10` | Seconds for S2S HTTP calls. |
| `APP_FRONTEND_URL` | `https://tickets.tuvens.com` | Base for `event_url` / `widget_embed_url` in the S2S create response and all outbound links. |
| `CORS_ALLOWED_ORIGINS` | `https://tuvens.com,https://www.tuvens.com,https://tickets.tuvens.com` | Comma-separated allow-list (config/cors.php). Never `*` in production — cookies ride on these responses. |

## Frontend environment (Vite)

| Env | Example | Purpose |
|---|---|---|
| `VITE_API_URL_CLIENT` / `VITE_API_URL_SERVER` | `https://tickets.tuvens.com/api` | Standard hi.events API endpoints. |
| `VITE_FRONTEND_URL` | `https://tickets.tuvens.com` | Standard. |
| `VITE_TUVENS_URL` | `https://tuvens.com` | Target of the "View on Tuvens" dashboard back-link. |
| `VITE_APP_NAME` / `VITE_APP_LOGO_*` / `VITE_APP_*_COLOR` | *(branding pass)* | Tuvens branding for the ticketing surface. |

No `VITE_TUVENS_SHARED_SECRET` or `VITE_SKIP_TUVENS_VALIDATION` exist —
the browser never holds integration secrets; if you find yourself wanting
either, the design is being violated.

## Integration surface (for ops reference)

- `POST /auth/cross-app/validate` — browser exchange of the one-time SSO
  code (`throttle:10,1`). Codes arrive on `/auth/cross-app#code=…&next=…`.
- `POST /tuvens/events`, `PUT /tuvens/events/{id}` — HMAC-authenticated S2S
  from tuvens-api (`X-Tuvens-Timestamp`/`X-Tuvens-Signature`,
  `X-On-Behalf-Of`). Externally `/api/tuvens/events…` behind the proxy.
- Outgoing webhooks (Spatie): per-event registration at S2S create time,
  `Signature: hex(HMAC-SHA256(webhook_secret, rawBody))`, envelope
  `{event_type, event_sent_at, payload}`.

## Local development bring-up

The dev stack is `docker/development/docker-compose.dev.yml`: nginx terminates
TLS on **https://localhost:8443** and proxies `/` → the SSR frontend
(`frontend:5678`, `yarn dev:ssr`) and `/api/` → the backend. Laravel reads
`backend/.env` through the volume mount — that gitignored file is where the
shared secret and any real credentials live. The **tracked**
`docker/development/.env` only feeds compose interpolation (ports, URLs, DB
name) and must never contain secrets; for local-only overrides use
`docker/development/.env.local` (gitignored).

```bash
cd docker/development
./start-dev.sh                # or: ./start-dev.sh --certs=signed (mkcert)
# equivalent manual sequence:
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec -T backend composer install --ignore-platform-reqs --no-interaction
docker compose -f docker-compose.dev.yml exec backend php artisan migrate   # applies external_user_id / external_account_id
```

Verify: `curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/api/` → 200 (backend; there is no dedicated /api/health route — the container healthcheck covers liveness),
`curl -sk https://localhost:8443/ | head` (SSR frontend),
`https://localhost:8443/auth/cross-app` loads and shows the sign-in error
state — a 401 on the code exchange is **expected** locally until the
tuvens-api S2S counterpart exists.

Known local pitfalls (root causes found 2026-07-21): the SSR container must
serve plain HTTP on 5678 (nginx does TLS — do not re-add vite/server.js
`https` blocks); stale pre-hardening env vars (`VITE_TUVENS_SHARED_SECRET`,
`VITE_SKIP_TUVENS_VALIDATION`, `MAIN_BACKEND_RETRY_ATTEMPTS`,
`MAIN_BACKEND_CACHE_TTL`, `AWS_COGNITO_*`) are dead — remove them; with the
MinIO endpoint configured, `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` must
be the MinIO credentials, never real AWS keys.

## Operator checkpoints (page, don't improvise)

1. ~~Rotate + provision `MAIN_BACKEND_SHARED_SECRET` both sides.~~ **Done 2026-07-21** (fingerprint-verified both sides; old value burned).
2. DNS + TLS for `tickets.tuvens.com`.
3. Stripe test account for end-to-end fee verification.
