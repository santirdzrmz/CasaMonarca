# Security Hardening

Record of the application-level hardening applied on 2026-10-23 and the
server-side steps that still have to be done by whoever administers each
environment. Code changes live in the API; infrastructure steps cannot be
applied from the repository.

## Applied in the application

### Dependencies

| Area | Change |
| --- | --- |
| PHP image | `API/Dockerfile` pinned to `php:8.3.36-apache` (fixes CVE-2026-109618 / CVE-2026-109619, unauthenticated RCE, highest risk under mod_php). |
| Composer | `composer update`: Laravel 13.1.1 → 13.38.0, dompdf 3.1.6, Symfony 7.4.21, Guzzle 8.3, CommonMark 2.10.4, Flysystem 3.36. `composer audit` reports no advisories. |
| npm (Web) | `npm audit fix` (dev tooling: Vite, Vitest, Babel, brace-expansion…). `npm audit` reports 0 vulnerabilities. Production bundle had none before. |

Re-check before every release:

```bash
cd API && composer audit
cd Web && npm audit
```

### Sign-in brute-force protection

| Endpoint | Control |
| --- | --- |
| `POST /login` | 5 failed passwords per email + IP → `429 login_rate_limited` for 5 minutes. Route throttle 20/min per client. |
| `POST /login/totp` | 5 failed codes **per account** → `429 totp_rate_limited` for 15 minutes and the pending challenge is dropped (the password step must be repeated). Restarting the password step does not reset the budget. Route throttle 20/min. |
| `POST /webauthn/login/*` | Route throttle 30/min. |
| `POST /totp/enroll/*` | Route throttle 10/min. |

Without these limits a 6-digit TOTP (three valid codes at any moment with the
±1 step window) could be guessed in roughly 330 000 requests.

Lockouts are written to the audit log as `auth.login.failed` /
`auth.totp.challenge.failed` with `metadata.reason = rate_limited`.

### TOTP replay protection

A code that already completed a sign-in is rejected if presented again within
its validity window (RFC 6238 §5.2). Used time-steps are tracked in the cache
for 120 s (`totp-used:{userId}:{counter}`); a reuse is audited with
`metadata.reason = code_reused`. Production must use a shared cache store
(`CACHE_STORE=database` default) if more than one API process runs.

### Account enumeration

Unknown emails now go through a dummy bcrypt comparison, so response time no
longer reveals which addresses have accounts.

### HTTP response headers

`App\Http\Middleware\SetSecurityHeaders` (global) adds to every API response:

- `X-Content-Type-Options: nosniff` — uploaded files cannot be sniffed into HTML/JS.
- `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'` — no clickjacking.
- `Content-Security-Policy: default-src 'none'; …` — the API never serves active content.
- `Referrer-Policy: no-referrer`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `Permissions-Policy`.
- `Strict-Transport-Security` on HTTPS requests.
- `Cache-Control: no-store, private` on authenticated responses, so migrant
  data is not kept in browser or proxy caches.

### Session and host configuration

| Setting | New default | Why |
| --- | --- | --- |
| `SESSION_ENCRYPT` | `true` | Session rows in the `sessions` table hold pending TOTP/WebAuthn challenge state; they are now encrypted with `APP_KEY`. Existing sessions are invalidated once after deploy. |
| `SESSION_SECURE_COOKIE` | `true` when `APP_ENV=production` | Session cookie never travels over plain HTTP. Set it explicitly to `true` on staging too. |
| `TRUSTED_HOSTS` | unset (opt-in) | Comma-separated Host allowlist. Set it on staging/production to stop Host-header poisoning (it also feeds the WebAuthn origin fallback). Ignored in local/testing. |

### Fail-closed production guard

The API refuses to boot when `APP_ENV=production` and `APP_DEBUG=true`, since
debug pages expose environment values and request data (and had the
CVE-2026-102279 XSS).

### Docker (development)

`compose.yaml` now publishes the API (8080) and Web (5173) ports on
`127.0.0.1` only, so the dev stack is not reachable from the local network.

## Pending — server / infrastructure (cannot be done from the repo)

Ordered by risk.

1. **Shared server PHP.** `server-environment.md` lists PHP 8.3.32 (PHP-FPM,
   managed by HostGator). It is affected by CVE-2026-109618/109619. Ask the
   provider / update in cPanel to **8.3.36 or later** and record the new version.
2. **Shared server MySQL port.** 3306 is open for remote TCP access. Close it
   in the firewall or restrict it to the API host's IP; the app connects locally.
3. **MySQL 5.7 is end-of-life** (no security patches since 2023). Plan the
   upgrade to 8.0 on the server and in `compose.yaml` together, so dev keeps
   matching production.
4. **Production `.env`**: `APP_ENV=production`, `APP_DEBUG=false`,
   `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`,
   `TRUSTED_HOSTS=<api host>`, `TRUSTED_PROXIES` only if behind a proxy,
   `TEMPORARY_DEV_ADMIN_INVITES=false`, `LOG_LEVEL=info` or higher.
   File mode `600` (the staging deploy script already does this).
5. **Web server headers for the SPA.** The Web build is served by Apache with
   a server-managed `.htaccess` (the deploy script preserves it). Add:
   `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`,
   `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, and a CSP such as
   `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
   img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none';
   base-uri 'self'; form-action 'self'`. Test sign-in, passkeys and PDF
   previews after enabling it.
6. **Encrypted backups only** — use `scripts/backup-database-encrypted.sh`.
7. **Developer workstations**: bind local MySQL to `127.0.0.1`
   (`bind-address=127.0.0.1` in `my.ini`), use a strong `root` password, and
   keep the registry KEK file readable only by the developer account.

## Related work on other branches

- Registry payload encryption at rest (AES-256-GCM envelope):
  `feature/registry-payload-encryption`. Until it is merged, questionnaire
  payloads are stored in plaintext.
- Department attribution for all modifications: `feature/department-attribution`.
