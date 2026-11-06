# CasaMonarca

Instructions and handoff notes for anyone (human or AI agent) picking up this repository.
Subproject details live in `API/README.md`, `Web/README.md` and `API/docs/`.

## Handoff

_Last updated: 2026-11-05._

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
| `registry:kek-generate <id>` | Create a registry KEK file |
| `registry:encrypt-backfill [--dry-run] [--purge-plaintext]` | Encrypt existing payloads |
| `verification-packages:generate-signing-key` | Signing keys for exported verification packages |
| `security-challenges:expire` | Expire stale WebAuthn challenge intents |
| `registry:migrant-drafts:purge` | Delete expired drafts |

### Git remotes and workflow

- `origin` is https://github.com/santirdzrmz/CasaMonarca, the owner's fork. Push here.
- `upstream` is https://github.com/Krul-dev/CasaMonarca, maintained by Krul-dev. A fresh clone does not have this remote; add it with `git remote add upstream https://github.com/Krul-dev/CasaMonarca.git`.
- Upstream flow: feature branch → PR to `staging` (1 approval + green CI) → `main`.
- **Decision (2026-11):** do not open PRs to or merge into Krul-dev yet. The fork's `main` should be fully working on its own first.

### Current status

- **Fork `main`:** upstream plus five fork feature branches, merged through `integration/fork-features` (still on origin):
  1. `security-hardening`
  2. `registry-payload-encryption`
  3. `department-attribution`
  4. `registry-statistics`
  5. `synthetic-registry-data`
- **Extra commit:** `c909edb` seeds the local admin with a department.
- **CI:** the first CI run on the fork passed both jobs, Backend (PHP 8.3 / MySQL 5.7.44) and Frontend (Node 24). Run: https://github.com/santirdzrmz/CasaMonarca/actions/runs/41720552595
- **Not yet run locally:** the merged app has not been started in Docker. The previous machine had no PHP, Node or Docker, so CI is the only verification so far.

### Most recent work

1. Merged the five fork branches and resolved the only conflict, in `API/.env.example`, by keeping both blocks.
2. Fixed a fresh-install bug: department attribution is enforced by default, and the seeded admin had no department, so every write request was rejected. `DatabaseSeeder` now assigns `information_technology`, and `tests/Feature/DatabaseSeederDepartmentTest.php` covers it.
3. Explained how data-at-rest encryption works, and wrote proposals for generating and storing the registry master key (KEK), with a diagram and a browser proof of concept. The proposals are in Spanish in `API/docs/operations/registry-kek-proposals.html`; open it in a browser, and the PoC uses WebCrypto.

### Key decisions and why

- **Envelope encryption for registry and ARCO payloads.** AES-256-GCM, with a random DEK per save, wrapped by a KEK.
  - Both layers bind `casamonarca|env|table|id|purpose|v1` as AAD, so ciphertext cannot be moved between records, columns or environments.
  - The envelope is stored in `<column>_enc` as `{v, alg, kek, wk, ct}`.
  - Reads fail closed: if decryption fails, there is no fallback to plaintext.
  - Encryption is disabled by default until a KEK exists. See `docs/operations/data-at-rest-encryption.md` and `registry-encryption-implementation.md`.
- **KEK custody plan (proposal B, recommended).**
  - Now: keep the KEK in a file and split an offline backup with Shamir 2-of-3 among Dirección, TI and Legal. This costs $0 and removes the key-loss risk.
  - Later: move to AWS KMS (proposal C, about USD 2/month) once an AWS account exists, and to a KMS decryption broker as the long-term goal (proposal D).
  - Rejected: passphrase-derived keys (no persistent process on shared hosting) and YubiKeys as the KEK (PIN or touch on every use would block background jobs).
  - The `KekProvider` interface lets KMS replace the file provider without re-encrypting payloads.
- **Department attribution enforced by default** (`DEPARTMENT_ATTRIBUTION_ENFORCED=true`). Every change must carry an `X-Acting-Department` header for one of the user's departments. Tests turn it off in `phpunit.xml`.
- **MySQL 5.7.44** in compose and CI, because that is what the HostGator target runs.

### Known bugs and open issues

- **Registry encryption is off.** No KEK has been generated, so registry and ARCO payloads are stored as plaintext JSON.
- **Uploaded files are plaintext.** Migrant documents, ARCO archives and exports are not encrypted yet (Phase 3 in the encryption doc).
- **Hosting encryption is unverified.** HostGator has not confirmed disk, snapshot or backup encryption.
- **Stale encryption doc.** The "Current Implementation" table in `docs/operations/data-at-rest-encryption.md` still lists payload encryption as "Planned", but the code is merged.
- **README mismatches.** The root `README.md` says MySQL 8.0, while compose and CI use 5.7.44. Its clone instructions point to Krul-dev and branch `feature/team-601-3-integrate`.
- **Untested PoC.** The KEK proof of concept matches `EnvelopeCipher`'s format but has not been tested against the PHP code.
- **Committed dev secrets.** `API/.env.dev` contains a dev `APP_KEY` and the local admin password. Treat both as public and never reuse them outside local Docker.
- **AI instruction files.** The root `.gitignore` ignores `AGENTS.md` as local AI instructions. `CLAUDE.md` is committed on this fork; drop it from any PR to upstream if the maintainers do not want it.

### Next steps

1. On the new machine, run `./scripts/dev-init.sh` and smoke-test the main flows: log in as the local admin, create a registration and move it through review, then file an ARCO request.
2. Decide on the KEK proposal. If it is B, add `registry:kek-split` and `registry:kek-restore` commands and write the custody procedure.
3. Generate the KEK, then turn encryption on in stages:
   1. Set `REGISTRY_ENCRYPTION_ENABLED=true` with `WRITE_PLAINTEXT=true`.
   2. Run `registry:encrypt-backfill --dry-run`, then run the real backfill.
   3. Test a restore of the key.
   4. Only after that, run `--purge-plaintext`.
4. Update the stale table in `data-at-rest-encryption.md` and fix the README's MySQL version.
5. When the fork is stable, open a PR from a feature branch to `Krul-dev/CasaMonarca` `staging`.

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
| `REGISTRY_ENCRYPTION_ENABLED`, `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT`, `REGISTRY_KEK_ID`, `REGISTRY_KEY_DIRECTORY`, `REGISTRY_ENCRYPTION_ENV` | Set during the encryption rollout. The key directory must be outside the repo and web root. |
| `VERIFICATION_PACKAGE_SIGNING_KEY_ID`, `VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64`, `VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64` | Generate them with `php artisan verification-packages:generate-signing-key`. Leave them empty in dev for unsigned packages. |
| `MAIL_*` | The production mail provider. Dev logs mail instead of sending it. |
| `AWS_*` | Not used yet. Needed when KMS (proposal C) is adopted. |
| `FEATURE_ARCO_ENABLED`, `ARCO_ENABLED_TYPES`, `FEATURE_MIGRANT_DOCUMENTS_ENABLED`, `MIGRANT_DOCUMENTS_MAX_PER_ENTRY`, `TEMPORARY_DEV_ADMIN_INVITES` | Feature flags. Keep `TEMPORARY_DEV_ADMIN_INVITES=false` in production. |

**API `.env.testing`.** Copy it from `API/.env.testing.example` for local test runs; it needs a `casamonarca_api_test` MySQL database.

**Web `.env`.** Copy it from `Web/.env.example`. It holds `VITE_APP_NAME`, `VITE_APP_CHANNEL`, `VITE_API_BASE_URL`, `API_PROXY_TARGET`, `VITE_ARCO_ENABLED`, `VITE_ARCO_ENABLED_TYPES` and `VITE_MIGRANT_DOCUMENTS_ENABLED`. Playwright optionally reads `PLAYWRIGHT_BASE_URL`, `PLAYWRIGHT_PORT` and `PLAYWRIGHT_SKIP_WEBSERVER`.

**Key files and credentials (outside the repo, never commit):**

- **Registry KEK files.** `<kek_id>.key` in `REGISTRY_KEY_DIRECTORY`, mode 600, plus their offline backup or Shamir shares. None exist yet. **Losing a KEK destroys the data it protects.**
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

The previous Windows machine had no `gh`, `node`, `python`, `php`, `composer` or `docker`. The GitHub API was used through `curl` and PowerShell, and CI was the only way to run tests. Install PHP 8.3, Composer 2, Node 24, npm 11 and Docker on the new machine to work locally.
