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
| `MAIN_BACKEND_SHARED_SECRET` | *(operator-provisioned)* | `HIEVENTS_S2S_SECRET` counterpart. HMAC key for both directions of the S2S seam. **Operator checkpoint: the original development secret was exposed and MUST be rotated before any deployment; provision the rotated value here and in tuvens-api, never in code or docs.** |
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

## Operator checkpoints (page, don't improvise)

1. Rotate + provision `MAIN_BACKEND_SHARED_SECRET` both sides.
2. DNS + TLS for `tickets.tuvens.com`.
3. Stripe test account for end-to-end fee verification.
