# CasaMonarca

Instructions and handoff notes for anyone (human or AI agent) picking up this repository.
Subproject details live in `API/README.md`, `Web/README.md` and `API/docs/`.

## Handoff

_Last updated: 2026-11-13._

### What the project does

Casa Monarca is a migrant-shelter management system. Staff register migrants, attach and sign
supporting documents, and handle ARCO privacy requests: access, rectification and cancellation
under Mexican data-protection law. Registrations follow a review workflow:
`draft → pending_review → pending_approval → approved | rejected`, with a `changes_requested` loop.
Every sensitive action writes an append-only audit event.

Roles are `admin`, `coordinator`, `non_coordinator` and `volunteer`. Staff authenticate with a
password plus passkeys/WebAuthn (YubiKeys) or TOTP. Sensitive operations use an options→verify
challenge: the intent is stored in the session and in `security_challenge_intents`, and the
server re-checks the payload hash before acting.

### How it is structured

```text
CasaMonarca/
├── API/        Laravel 13, PHP 8.3, MySQL 5.7.44 (production target: shared HostGator/cPanel)
│   ├── app/                 Controllers, Services, Models, Middleware, Console/Commands
│   ├── routes/api.php       All API routes. Middleware: requireRole, requireSecurityEnrollment,
│   │                        requireActiveAccount, requireFeature, requireDepartment
│   ├── routes/console.php   Closure commands (users:departments, verification-packages:generate-signing-key, ...)
│   ├── docs/                Architecture, authorization and operations docs (read these first)
│   └── scripts/             backup-database-encrypted.sh, deploy-staging.sh
├── Web/        React 19, Vite 8, TypeScript. Bilingual UI via t("en", "es").
│   └── src/config/appRoutes.ts   Hand-rolled routing (no router library)
├── scripts/    dev-init.sh (Docker dev bootstrap), deploy-staging-api.sh, deploy-staging-web.sh
├── compose.yaml            Docker dev stack: web :5173, api :8080, MySQL 5.7.44
└── .github/workflows/ci.yaml   CI on push/PR to main and staging, plus manual dispatch
```

### Install, run and test

The quickest path uses Docker:

```bash
./scripts/dev-init.sh      # docker compose up --build, composer install, migrate:fresh --seed
```

- **URLs:** web at http://localhost:5173 and API at http://localhost:8080.
- **Env files:** compose mounts `API/.env.dev` and `Web/.env.dev`. Both are committed and contain dev-only values.
- **Seeding:** creates the local admin from `LOCAL_ADMIN_EMAIL` / `LOCAL_ADMIN_PASSWORD` in `API/.env.dev` and assigns it the `information_technology` department.

To run without Docker, follow the root `README.md`: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `migrate`, `serve`, then `npm install` and `npm run dev` in `Web/`.

**Tests (same as CI):**

```bash
cd API && composer validate --strict && php artisan test     # needs MySQL; phpunit.xml sets DEPARTMENT_ATTRIBUTION_ENFORCED=false
cd Web && npm ci && npm run lint && npm run typecheck && npm test && npm run build
cd Web && npm run qa:visual                                 # Playwright visual QA; not part of CI
```

**Useful artisan commands:**

| Command | Purpose |
|---|---|
| `users:departments <email> [dept...] [--replace]` | View or assign departments |
| `registry:synthetic` | Generate or purge fake registry data (`docs/operations/synthetic-data.md`) |
| `registry:keys-init`, `keys-enroll <email>`, `keys-grant <email>`, `keys-revoke <email>`, `keys-rewrap` | Per-user registry keys and rotation; see `docs/operations/registry-user-keys.md` |
| `registry:encrypt-backfill --private-dir=… [--dry-run] [--purge-plaintext]` | Encrypt existing payloads (needs the domain private keys to verify) |
| `registry:demo-setup`, `registry:demo-totp <email>` | LOCAL only: demo users per level, demo keys and TOTP codes; see `docs/operations/registry-poc-demo.md` |
| `verification-packages:generate-signing-key` | Signing keys for exported verification packages |
| `security-challenges:expire` | Expire stale WebAuthn challenge intents |
| `registry:migrant-drafts:purge` | Delete expired drafts |

### Git remotes and workflow

- `origin` is https://github.com/santirdzrmz/CasaMonarca, the owner's fork. Push here.
- `upstream` is https://github.com/Krul-dev/CasaMonarca, maintained by Krul-dev. A fresh clone does not have this remote; add it with `git remote add upstream https://github.com/Krul-dev/CasaMonarca.git`.
- Upstream flow: feature branch → PR to `staging` (1 approval + green CI) → `main`.
- **Decision (2026-11):** do not open PRs to or merge into Krul-dev yet. The fork's `main` should be fully working on its own first.
- **Remote names differ per clone.** The clones at `C:\Users\santi\CasaMonarca` and `OneDrive\Documents\GitHub\CasaMonarca` still have `origin` = Krul-dev and `fork` = the owner's fork; push with `git push fork …`. GitHub Desktop only lists `origin` branches, so rename the remotes (`origin`→`upstream`, `fork`→`origin`) to see the fork's branches there.
- **No AI attribution.** Commits and PRs must not carry `Co-Authored-By: Claude` or "Generated with Claude Code" lines. Eight older merge/doc commits on `main` (`5cdb57e` … `02e2551`) still have one; rewriting them needs a force push and was not done.

### Current status

- **Fork `main`:** upstream plus the fork feature branches:
  1. `security-hardening`, `registry-payload-encryption`, `department-attribution`, `registry-statistics`, `synthetic-registry-data` (through `integration/fork-features`, plus `c909edb` seeding the local admin's department).
  2. `feature/demo-poc` (azulichis): **per-user registry keys**, now the only key scheme. The symmetric file KEK was removed.
  3. `feature/statistics-requested-services`: "Servicios solicitados" card on Statistics.
- **Verified locally (2026-11-13):** API 265 tests and Web 60 tests pass; lint, typecheck and build pass. CI runs on every push to `main`.

### Most recent work

1. Merged `feature/demo-poc` and `feature/statistics-requested-services` into `main` through `integration/per-user-keys`.
2. Removed the file-KEK mode entirely, because per-user keys is the chosen design:
   - Deleted `FileKekProvider`, `RoutingKekProvider` and `registry:kek-generate`.
   - Removed the `REGISTRY_KEK_ID`, `REGISTRY_KEY_DIRECTORY` and `REGISTRY_ENCRYPTION_MODE` settings.
   - Deleted the KEK proposals (`registry-kek-proposals.html`) and the file-KEK guide (`registry-encryption-implementation.md`).
   - `REGISTRY_ENCRYPTION_ENABLED` alone now turns on per-user keys.
   - API responses still report `mode` as `publickey` or `off` for the Web panels.
3. Added the requested-services statistics:
   - per-service counts;
   - people per area (Humanitaria, Legal, Psicosocial), counted once per area;
   - people per area by month.

   The service-to-area mapping is `SERVICE_AREAS` in `MigrantRegistryStatisticsService`. "Canalización a Centro de Salud" and "Búsqueda de vivienda" sit in Humanitaria, and "RFC, CURP o NSS" in Legal, pending confirmation from the team.

### Key decisions and why

- **Envelope encryption for registry and ARCO payloads.** AES-256-GCM, with a random DEK per save.
  - Both layers bind `casamonarca|env|table|id|purpose|v1` as AAD, so ciphertext cannot be moved between records, columns or environments.
  - The envelope is stored in `<column>_enc` as `{v, alg, kek, wk, ct}`.
  - Reads fail closed: if decryption fails, there is no fallback to plaintext. Missing keys return HTTP 423 (`registry_keys_locked`).
  - Encryption is off by default (`REGISTRY_ENCRYPTION_ENABLED=false`) until the domain keys exist. See `docs/operations/registry-user-keys.md` and `data-at-rest-encryption.md`.
- **Per-user keys (decision 2026-11; replaces the file KEK and proposals A–D).**
  - The DEK is wrapped with an X25519 public key per domain (`PublicKeyKekProvider`):
    - `cand` covers records before approval and pending changes;
    - `proc` covers approved records and ARCO;
    - approval re-seals a record from `cand` to `proc`.
  - The server holds only the public keys. The admin generates the domain private keys offline (`registry:keys-init`, `age` for a YubiKey) and delivers them to each coordinator or admin in a personal key file (`keys-enroll`, `keys-grant` with fingerprint check).
  - Coordinators and admins unlock their keys per session (`POST /registry/keys/unlock`, TTL `REGISTRY_SESSION_KEY_TTL`).
  - Operators read only records a coordinator grants them: a signed, time-limited grant tied to the operator's session key. Volunteers write but cannot read encrypted data.
  - Known limits are listed in `registry-user-keys.md`:
    - keys sit in the server cache during a session;
    - the `/registry/keys/*` and `/registry/access/*` routes skip `requireDepartment`;
    - files and PDFs are not covered yet.
  - **Losing every copy of the domain private keys destroys the data.** Keep a second YubiKey or offline copy.
- **Department attribution enforced by default** (`DEPARTMENT_ATTRIBUTION_ENFORCED=true`). Every change must carry an `X-Acting-Department` header for one of the user's departments. Tests turn it off in `phpunit.xml`.
- **MySQL 5.7.44** in compose and CI, because that is what the HostGator target runs.

### Known bugs and open issues

- **Registry encryption is off.** No domain keys have been generated outside the local demo, so registry and ARCO payloads are stored as plaintext JSON.
- **Uploaded files are plaintext.** Migrant documents, ARCO archives and exports are not encrypted yet (Phase 3 in the encryption doc).
- **Hosting encryption is unverified.** HostGator has not confirmed disk, snapshot or backup encryption.
- **New Symfony advisories.** `composer audit` reports 7 (http-foundation, http-kernel, mime, routing; fixed in 7.4.23). Run `composer update` and the tests.
- **Flaky tests.** `RegistrySyntheticDataTest::test_the_same_seed_produces_the_same_data_set` and `AuditEventApiTest::test_admin_can_search_audit_events_by_metadata_and_resource_ids` fail occasionally in full runs and pass when rerun.
- **README mismatches.** The root `README.md` says MySQL 8.0, while compose and CI use 5.7.44. Its clone instructions point to Krul-dev and branch `feature/team-601-3-integrate`.
- **Destructive demo guide.** `registry-poc-demo.md` runs `migrate:fresh` and drops older PoC databases, and it refers to a `registry-professor-model.patch` that is not in the repo.
- **Committed dev secrets.** `API/.env.dev` contains a dev `APP_KEY` and the local admin password. Treat both as public and never reuse them outside local Docker.
- **AI instruction files.** The root `.gitignore` ignores `AGENTS.md` as local AI instructions. `CLAUDE.md` is committed on this fork; drop it from any PR to upstream if the maintainers do not want it.

### Next steps

1. Run `composer update` for the Symfony advisories, then rerun the tests.
2. Try the per-user keys end to end locally with `registry-poc-demo.md`. Note that it wipes the local database.
3. Roll out per-user keys following `registry-user-keys.md`:
   1. `keys-init` on the admin's computer.
   2. `migrate`.
   3. `keys-enroll` and `keys-grant` for each coordinator and admin.
   4. Set `REGISTRY_ENCRYPTION_ENABLED=true` with `WRITE_PLAINTEXT=true`.
   5. Run the backfill with `--private-dir`.
   6. Test that a key backup restores.
   7. Only then run `--purge-plaintext`.
4. Close the per-user keys gaps:
   - apply `requireDepartment` to the `/registry/keys/*` and `/registry/access/*` routes;
   - add unlock screens to the other registry pages;
   - encrypt files and PDFs.
5. Confirm the service-to-area mapping for Statistics with the team, and fix the README's MySQL version.
6. When the fork is stable, open a PR from a feature branch to `Krul-dev/CasaMonarca` `staging`.

### What is not in the repo

These have to be created or copied by hand. Secret values are never written here.

**API `.env` (production, staging or non-Docker local).** Create it from `API/.env.example`. Docker dev uses the committed `API/.env.dev` instead.

| Variable | Where it comes from |
|---|---|
| `APP_KEY` | Generate it with `php artisan key:generate`; use a separate one per environment. `APP_PREVIOUS_KEYS` is only needed when rotating. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The MySQL database and user you create (cPanel on HostGator; see `API/README.md`) |
| `LOCAL_ADMIN_EMAIL`, `LOCAL_ADMIN_PASSWORD` | Choose them yourself. Used only when `APP_ENV=local`. |
| `TRUSTED_HOSTS`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` | The deployment's domain and proxy setup (`docs/operations/security-hardening.md`) |
| `DEPARTMENT_ATTRIBUTION_ENFORCED` | Keep `true`; see `docs/operations/department-attribution.md` |
| `REGISTRY_ENCRYPTION_ENABLED`, `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT`, `REGISTRY_ENCRYPTION_ENV` | Set during the encryption rollout (`registry-user-keys.md`). |
| `REGISTRY_PK_CAND_ID`, `REGISTRY_PK_CAND_PUBLIC`, `REGISTRY_PK_PROC_ID`, `REGISTRY_PK_PROC_PUBLIC` | Public domain keys printed by `registry:keys-init`. Public keys only; private keys never go in `.env`. |
| `REGISTRY_SESSION_KEY_TTL`, `REGISTRY_OPERATOR_SESSION_TTL` | How long unlocked keys and operator session keys last (seconds). |
| `VERIFICATION_PACKAGE_SIGNING_KEY_ID`, `VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64`, `VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64` | Generate them with `php artisan verification-packages:generate-signing-key`. Leave them empty in dev for unsigned packages. |
| `MAIL_*` | The production mail provider. Dev logs mail instead of sending it. |
| `AWS_*` | Not used yet. Planned only for file encryption keys (KMS). |
| `FEATURE_ARCO_ENABLED`, `ARCO_ENABLED_TYPES`, `FEATURE_MIGRANT_DOCUMENTS_ENABLED`, `MIGRANT_DOCUMENTS_MAX_PER_ENTRY`, `TEMPORARY_DEV_ADMIN_INVITES` | Feature flags. Keep `TEMPORARY_DEV_ADMIN_INVITES=false` in production. |

**API `.env.testing`.** Copy it from `API/.env.testing.example` for local test runs; it needs a `casamonarca_api_test` MySQL database.

**Web `.env`.** Copy it from `Web/.env.example`. It holds `VITE_APP_NAME`, `VITE_APP_CHANNEL`, `VITE_API_BASE_URL`, `API_PROXY_TARGET`, `VITE_ARCO_ENABLED`, `VITE_ARCO_ENABLED_TYPES` and `VITE_MIGRANT_DOCUMENTS_ENABLED`. Playwright optionally reads `PLAYWRIGHT_BASE_URL`, `PLAYWRIGHT_PORT` and `PLAYWRIGHT_SKIP_WEBSERVER`.

**Key files and credentials (outside the repo, never commit):**

- **Registry private keys.** These exist only for the local demo so far.
  - The domain private keys (`pk_*.age`, encrypted for the admin's YubiKey) live on the admin's computer and an offline backup.
  - Each coordinator or admin holds a personal key file plus its passphrase.
  - **Losing every copy of a domain private key destroys the data it protects.**
- **Database backups.** `age` keys for `API/scripts/backup-database-encrypted.sh`:
  - `DB_BACKUP_AGE_RECIPIENT` is the public key and goes on the server.
  - The matching private identity is kept offline and is needed to restore.
  - `MYSQL_DEFAULTS_FILE` is a MySQL client options file holding DB credentials.
  - Backups go to `storage/app/backups/database`, which is gitignored.
- **Staging deploy:**
  - SSH key `~/.ssh/casamonarca_vps`
  - GitHub token file on the server (`GITHUB_TOKEN_FILE`, e.g. `/home/casamonarca/.config/casamonarca/github_token`)
  - `GITHUB_USERNAME`
  - `REMOTE_HOST`, `REMOTE_USER` and `REMOTE_APP_DIR`, which have defaults in the deploy scripts
- **GitHub push access to the fork.** Set it up with Git Credential Manager or `gh auth login` on the new machine.

**Databases and data:**

- **Local Docker MySQL.** The data lives in the `db-data` volume and is not in the repo. It is disposable: `dev-init.sh` runs `migrate:fresh --seed`.
- **Staging and production MySQL.** These live on the servers. Back them up with the encrypted backup script; never copy raw dumps into the repo.
- **Uploaded documents.** These are stored under `API/storage/app/private` on each server and are gitignored.

### Local tooling notes

The current Windows machine has PHP 8.3.36, Composer 2, Node 24, npm, MySQL 8.0 (local, non-Docker), Docker Desktop and `gh`. Notes:

- API tests need `OPENSSL_CONF=<PHP dir>\extras\ssl\openssl.cnf`, or the tests that generate RSA keys fail.
- `API/.env.testing` points at `casamonarca_api_test`, so `RefreshDatabase` never wipes the dev database.
- Non-Docker dev servers run through a local `.claude/launch.json` (`api` on :8000, `web` on :5173). It is not committed.
