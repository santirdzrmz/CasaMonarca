# Data-at-Rest Encryption

## Objective

Protect sensitive migrant registrations, private files, database storage, and
recoverable copies with defense in depth. Volume and backup encryption protect
offline media; the target application layer protects sensitive values before
they reach MySQL or private file storage.

The application-layer work in this document is a roadmap, not a claim about the
current deployment. It must preserve the existing registration workflow,
accent-insensitive search semantics, ARCO handling, document signatures, and
verification receipts.

## Current Implementation

| Data copy | Project control | Current requirement |
| --- | --- | --- |
| Encrypted logical backups | Enforced by repository tooling | Use `scripts/backup-database-encrypted.sh` with an `age` public recipient |
| Production MySQL data volume | Hosting-provider control | Obtain written confirmation that the volume is encrypted at rest |
| Provider snapshots and replicas | Hosting-provider control | Obtain written confirmation that every copy is encrypted |
| cPanel or provider-managed backups | Hosting-provider control | Verify encryption, key separation, retention, and deletion |
| Local Docker `db-data` volume | Developer-machine control | Store Docker's data root on an encrypted host disk |
| Registration and ARCO payloads | Application control | Planned: authenticated envelope encryption before persistence |
| Internal and migrant private files | Application control | Planned: streaming authenticated envelope encryption |
| Persisted ARCO archives and exports | Application control | Planned: encrypt every persisted artifact; keep transient bundles in memory |
| Application encryption keys | AWS KMS and application control | Planned: separate registry and file KMS keys; not provisioned yet |
| Recipient-protected ZIP delivery | Application control | Planned: optional AES-256 ZIP in addition to ordinary TLS delivery |

The production environment is shared HostGator/cPanel hosting. The application
deployment user cannot enable or verify physical disk encryption from Laravel,
MySQL, Docker Compose, or a migration. Until the hosting provider confirms the
items above, production volume, snapshot, replica, and provider-backup
encryption must be treated as **not verified**.

Database encryption does not cover files under Laravel's private storage disk.
Until the roadmap below is implemented, migrant supporting documents, document
revisions, persisted ARCO archives, and temporary exports must be treated as
plaintext at the application storage layer.

## Encrypted Logical Backups

The backup script streams `mysqldump` through `gzip` and directly into `age`.
It does not create an intermediate plaintext dump. `age` provides authenticated
public-key encryption, so the application server only needs a public recipient;
the private recovery identity must remain on a separate trusted system.
Tablespace metadata is excluded so the backup account does not need MySQL's
global `PROCESS` privilege.

### Prerequisites

- `age`, `mysqldump`, `gzip`, and `sha256sum`
- An age identity generated on an offline administrative workstation
- A MySQL client options file stored outside the repository with mode `600`
- A backup destination that is not web-accessible

Generate the recovery identity outside the application server:

```bash
age-keygen -o casamonarca-backup-identity.txt
chmod 600 casamonarca-backup-identity.txt
```

Record the printed `age1...` public recipient in the server's protected
operational configuration. Do not copy
`casamonarca-backup-identity.txt` to the application server.

Create a server-side MySQL options file such as
`/home/casamonarca/.config/casamonarca/backup-mysql.cnf`:

```ini
[client]
host=localhost
port=3306
user=BACKUP_USER
password=BACKUP_PASSWORD
```

Restrict it:

```bash
chmod 600 /home/casamonarca/.config/casamonarca/backup-mysql.cnf
```

Run a backup:

```bash
DB_BACKUP_DATABASE=casamonarca_api \
DB_BACKUP_AGE_RECIPIENT=age1REPLACE_WITH_PUBLIC_RECIPIENT \
MYSQL_DEFAULTS_FILE=/home/casamonarca/.config/casamonarca/backup-mysql.cnf \
DB_BACKUP_DIR=/home/casamonarca/backups/database \
DB_BACKUP_RETENTION_DAYS=30 \
./scripts/backup-database-encrypted.sh
```

Only `.sql.gz.age` files and their SHA-256 checksums should leave the server.
The checksum detects accidental corruption before recovery; authenticity and
confidentiality are provided by `age`.

### Scheduling and Off-Site Copies

Schedule the command with the hosting control panel or cron only after a manual
backup and recovery test succeeds. Put the public recipient and non-secret
paths in a deployment-user-owned wrapper or scheduler configuration; keep the
MySQL password only in the protected client options file.

Run at least one backup daily, alert when the command exits unsuccessfully, and
transfer the resulting encrypted archive plus checksum to independently
controlled off-site storage. A backup retained only on the application server
does not protect against server loss or destructive compromise. Apply the
documented retention policy to both the server copy and every off-site copy.

### Recovery Test

Verify the checksum before decrypting:

```bash
sha256sum --check database-YYYYMMDDTHHMMSSZ.sql.gz.age.sha256
```

Restore into an isolated recovery database, never directly over production:

```bash
age --decrypt \
  --identity casamonarca-backup-identity.txt \
  database-YYYYMMDDTHHMMSSZ.sql.gz.age \
  | gzip --decompress \
  | mysql --defaults-extra-file=recovery-mysql.cnf casamonarca_recovery
```

Perform and document a recovery test at least quarterly and after changing the
database version, backup script, encryption recipient, or hosting provider.

## Provider Verification Checklist

Request written answers from the hosting provider:

1. Are MySQL data volumes encrypted at rest?
2. Are all automatic backups, snapshots, temporary copies, and replicas encrypted?
3. Are encryption keys managed separately from stored data and backups?
4. Who can access the keys, and are key accesses audited?
5. What encryption and key-management services are used?
6. What are backup retention and secure-deletion periods?
7. Can customer-created unencrypted cPanel exports be disabled or restricted?
8. What happens to encrypted copies and keys when the service is terminated?

If the provider cannot meet or attest to these requirements, move production
MySQL to infrastructure where encrypted disks, snapshots, replicas, backup
policies, and keys are under organizational control.

## Target Application Architecture

### Registration and ARCO Payloads

Encrypt each complete questionnaire payload rather than maintaining a fragile
list of selected sensitive fields. The payload contains direct identifiers,
information about minors, health and protection concerns, travel routes,
payments to guides, support networks, distinctive marks, emergency contacts,
and free text. Encrypt at least:

- `migrant_registry_entries.payload_json`
- `migrant_registry_entries.pending_payload_json`
- ARCO `original_payload_json`
- ARCO `proposed_payload_json`

Keep only the metadata required to route work in plaintext: database IDs,
workflow status and pending action, assignee role, creator/requester IDs,
timestamps, and document/signature relationships. Review every new plaintext
projection as a disclosure, not merely as an implementation convenience.

Use authenticated encryption and bind each ciphertext to its environment,
resource type, record ID, payload purpose, and format version as additional
authenticated data. This prevents a valid ciphertext from being silently moved
to another record or payload slot. Continue hashing the canonical plaintext for
ARCO and signature integrity; do not replace those hashes with hashes of
ciphertext because re-encryption and key rotation must not invalidate receipts.

### Search and Filtering

The selected compatibility model is **server-side decrypt and scan**. The API
will accept search, filter, sort, and page parameters, apply plaintext workflow
filters first, decrypt the bounded candidate set, perform the same normalized
accent-insensitive partial matching used today, and return only the requested
page. Do not expose decrypted registration collections to the browser merely to
implement search.

This preserves current search behavior and avoids the equality and frequency
leakage of a general searchable projection. It is still an O(n) operation and
must have measured limits for candidate count, response time, memory, and
concurrent searches. Never make one AWS KMS request per candidate per search.
Use a bounded in-process data-key cache initially, and move decryption to the
broker described below before registry volume or concurrency exceeds the
documented thresholds. If measurements later require exact-match blind indexes,
use a separate search key and document the narrower query semantics and leakage;
do not add normalized plaintext indexes.

### Private Files and Generated Artifacts

Encrypt every persistent private object, including:

- Internal document revisions
- Migrant supporting documents
- Persisted ARCO PDFs and ZIP archives
- Persistent staging files, exports, and retry artifacts
- Sensitive filenames, labels, and object metadata where operationally possible

Use opaque storage object names. The existing maximum upload size is 512 MB, so
file encryption and decryption must be streaming and authenticated. Do not load
whole files into Laravel strings or use Laravel `Crypt` as a large-file format.
Select a maintained streaming encryption implementation with a documented file
format, chunk authentication, truncation detection, and final-stream
authentication; do not design a custom cryptographic format.

Generate a random 256-bit data-encryption key (DEK) for each object. Persist the
algorithm and format version, KMS key ID/version, wrapped DEK, authenticated
header, nonce/chunk parameters, and authentication data beside the ciphertext.
Stream plaintext only after the existing authorization and passkey gates pass,
and do not write decrypted content to persistent temporary storage.

PHP multipart handling may create plaintext temporary files before Laravel can
encrypt an upload. Provider volume encryption therefore remains required even
after application encryption is deployed. Deployment readiness must also verify
a vetted streaming crypto library, its required PHP extension, and AES ZIP
support; the current environment must not be assumed to provide `sodium` or
`ZipArchive` until production checks confirm them.

Preserve the SHA-256 and byte size of the plaintext revision for signing and
verification. Historical signatures remain attached to the original plaintext
content identity. Re-encryption, DEK rewrapping, and KEK rotation must not alter
the canonical hash or invalidate an existing receipt.

### ZIP Delivery

Support both delivery modes:

1. **Standard ZIP over TLS:** decrypt the encrypted stored ARCO archive while
   streaming the response. The recipient receives an ordinary ZIP.
2. **Recipient-protected ZIP:** optionally build an AES-256 encrypted ZIP with a
   strong, one-time passphrase. Display the passphrase once, never persist or
   log it, and deliver it through a separate channel.

Verify AES-256 ZIP interoperability with the supported client applications and
production `libzip` version before release. A verification ZIP that is built in
memory and returned immediately remains transient. If any ZIP or generated
artifact is persisted for retry, audit, or later download, encrypt the outer
object at rest; entries do not need a second application-encryption layer.

### Envelope Keys and Separation

Use separate symmetric AWS KMS customer-managed keys for these domains:

- `REGISTRY_KEK`: registration and ARCO payload DEKs
- `FILE_KEK`: document, supporting-file, and persisted-archive DEKs
- `SEARCH_INDEX_KEY`: reserve for a future blind-index design; do not provision
  it while decrypt-and-scan remains sufficient

Keep these independent from Laravel `APP_KEY`, document-signing keys, WebAuthn
credentials, and the offline `age` backup identity. For each payload or object:

1. Generate a random DEK through KMS or a vetted local cryptographic generator.
2. Encrypt using authenticated encryption.
3. Store only ciphertext, encryption metadata, and the KMS-wrapped DEK.
4. Request unwrapping only after authorization and workflow checks succeed.
5. Limit plaintext and unwrapped-key lifetime in memory.

Record the KEK ID and format version on every encrypted value. Rotate by making
a new KEK version active for writes, retaining old versions for reads, and
rewrapping DEKs in a resumable background process. Do not decrypt and re-encrypt
business content merely to rotate a KEK. Retain recovery access to every key for
as long as a backup containing dependent ciphertext may be restored.

If AWS KMS cannot be used during the first deployment, the temporary fallback is
separate environment-specific key files outside the repository and web root,
owned by the deployment account with mode `600`. This fallback protects a
database-only disclosure but does not materially isolate keys from compromise of
the same host and must have a scheduled replacement date.

### KMS Access and Decryption Broker

Initial KMS integration may use a narrowly scoped application IAM identity for
`GenerateDataKey` and `Decrypt`, constrained by key, environment, and encryption
context. This is an incremental control: it keeps KEKs non-exportable and makes
key use revocable and auditable, but a compromised Laravel process could still
ask KMS to decrypt data within that identity's permissions.

The stronger target is a separate decryption broker in a distinct runtime and
IAM identity. Laravel must have permission to call only the broker, not KMS. The
broker must:

- Accept structured resource operations, never arbitrary ciphertext
- Independently validate resource, role, workflow state, and recent passkey proof
- Bind KMS encryption context to environment, resource type, and resource ID
- Apply per-user, per-resource, and bulk-operation rate limits
- Emit append-only audit events without plaintext or key material
- Detect abnormal enumeration and support an operational kill switch
- Cache unwrapped keys only within explicit time, use-count, and byte limits

The broker boundary is useful only if it can validate claims independently of a
possibly compromised Laravel process. Broker hosting, its authoritative access
to identity/workflow state, and proof validation must therefore be designed and
threat-modeled before moving KMS permissions away from Laravel.

AWS Encryption SDK's hierarchical keyring can reduce KMS calls with cached
branch keys, but its supported-language and DynamoDB requirements must be
verified against the chosen broker implementation. It is not a reason to add an
unsupported cryptographic implementation to PHP.

### Role of YubiKeys

Continue using staff YubiKeys through WebAuthn as an authorization and recent
authentication factor. Existing FIDO/WebAuthn credentials cannot be reused as
general-purpose encryption keys. PIV-capable YubiKeys could unwrap small DEKs,
but PIN/touch and physical-presence requirements would prevent unattended
search, ARCO generation, background work, and reliable multi-user recovery.

A YubiHSM is a separate product and operating model. For these server workflows,
AWS KMS or a managed HSM is the selected KEK service; staff YubiKeys remain the
factor that authorizes sensitive operations, not the application's KEK store.

## Protection Boundaries

| Threat | Volume/backup encryption | Application encryption with KMS | Remaining limitation |
| --- | --- | --- | --- |
| Stolen disk, snapshot, or backup | Protects when keys are separate | Also protects sensitive payloads and objects | Provider coverage and key custody must be verified |
| Read-only database dump or DB credential leak | Does not protect a live database | Sensitive payloads remain ciphertext if KMS access is separate | Plaintext workflow metadata and any projections remain visible |
| SQL injection that reads arbitrary rows | Does not protect | Reduces direct disclosure of encrypted columns | A decryption-capable endpoint or compromised app may still expose plaintext |
| Compromised authorized user | Does not protect | Does not prevent legitimate reads within the user's permissions | Least privilege, passkeys, export controls, and audit remain required |
| Compromised Laravel process | Does not protect | Direct KMS use limits key extraction but not legitimate decrypt calls | Separate broker reduces reach but cannot hide data the application must display |
| Deletion, corruption, rollback, or ransomware | Does not protect | AEAD detects modification, not deletion or rollback by itself | Backups, immutable audit/version checks, and recovery procedures are required |
| Plaintext already downloaded or displayed | Does not protect | Does not protect | Endpoint security, session controls, and handling policy are required |

Application encryption is valuable because a stolen database or storage copy no
longer contains the most sensitive plaintext. It is not end-to-end encryption:
the service must decrypt data for authorized workflows. User-held end-to-end
keys would resist a compromised server more strongly, but would prevent the
selected server-side search, background ARCO processing, and multi-user recovery.

### SQL Injection Controls

Encryption is a containment measure, not the primary SQL-injection defense. An
injection can still enumerate plaintext metadata, corrupt or delete data, alter
roles or workflow state, and possibly reach an endpoint that performs authorized
decryption. Maintain these controls independently:

- Parameterized query bindings for values
- Strict allowlists for dynamic sort columns, directions, filters, and JSON paths
- A least-privilege runtime database account without schema, `FILE`, or admin rights
- Separate migration and backup identities
- `APP_DEBUG=false` and redacted production errors and logs
- Authorization tests, query review, monitoring, and alerts for bulk reads

Authenticated encryption must bind ciphertext to record identity and version to
detect swapping. Use transaction/version checks and immutable audit records to
detect rollback; AEAD alone cannot detect that an attacker restored an older,
otherwise valid ciphertext.

## AWS KMS Cost Estimate

As of 2026-07-29, AWS lists symmetric customer-managed keys at USD 1 per key per
month, with the first and second on-demand rotations adding USD 1 per key per
month. Later rotations do not increase the monthly key-storage charge. The first
20,000 eligible KMS requests per account per month are free, followed by USD
0.03 per 10,000 requests.

With `REGISTRY_KEK` and `FILE_KEK`, estimated KMS-only cost is:

| Symmetric requests/month | Two unrotated keys | Notes |
| ---: | ---: | --- |
| 10,000 | $2.00 | Requests remain within the free tier |
| 50,000 | $2.09 | 30,000 billable requests |
| 100,000 | $2.24 | 80,000 billable requests |
| 1,000,000 | $4.94 | 980,000 billable requests |
| 3,000,000 | $10.94 | 2,980,000 billable requests |
| 10,000,000 | $31.94 | 9,980,000 billable requests |

After one rotation of both keys, the fixed portion becomes approximately $4 per
month; after two or more rotations, approximately $6 per month. Count one data-
key generation request per new or replaced encrypted payload/object and one
decrypt request per cold DEK unwrap. AWS hosting, DynamoDB for hierarchical
keyrings, CloudTrail/CloudWatch, networking, and broker compute are separate.

Do not estimate decrypt-and-scan as one KMS call per record. For example, 2,000
registrations searched 100 times daily would create about six million monthly
calls and unacceptable request latency. A bounded cache or hierarchical broker
should normally keep this deployment near the KMS free request allowance, but
measure with production-like workloads before committing to that forecast.

KMS is available in `mx-central-1`. Because the current HostGator server is in
Jacksonville, `us-east-1` may provide lower latency; select the region only after
confirming organizational data-residency requirements and measuring round-trip
latency. Do not enable multi-Region keys, asymmetric keys, CloudHSM, or a custom
key store unless a requirement justifies their added cost and operation.

## Implementation Roadmap

### Phase 1: Readiness and Inventory

- Require verified TLS for every non-local Laravel-to-MySQL connection.
- Inventory production, staging, backup, snapshot, replica, export, queue,
  cache, log, upload-temp, and generated-file copies.
- Assign an owner, retention period, and deletion mechanism to each copy.
- Confirm production support for the selected streaming crypto library, PHP
  extensions, `ZipArchive`, and AES-256 ZIP interoperability.
- Select the KMS region, create separate registry and file keys, and configure
  least-privilege IAM, encryption contexts, key-use logging, alarms, and recovery.
- Reconcile ARCO cancellation with backup retention: purged data may remain
  recoverable until every encrypted backup containing it expires.

### Phase 2: Registration Payload Migration

> **Status (2026-10-02):** code scaffolding merged behind
> `REGISTRY_ENCRYPTION_ENABLED` (default off). Implemented: AES-256-GCM envelope
> cipher with per-payload DEK and AAD binding (`app/Services/Security/Encryption`),
> file-based KEK provider (interim, see fallback above), additive `*_enc` columns,
> dual-read `EncryptedPayload` cast, `registry:kek-generate` and resumable
> `registry:encrypt-backfill` (verifies by decrypt-and-compare). Not yet done: KMS
> provider, decrypt-and-scan search parity tests, dropping plaintext, Phase 3 files.
> Feature tests need a MySQL test schema; run `php artisan test`.

- Add versioned ciphertext/envelope columns without removing plaintext columns.
- Implement dual reads and controlled dual writes, preferring ciphertext when
  present and falling back only during migration.
- Backfill current registrations, pending rectifications, and ARCO snapshots in
  resumable batches with counts, checkpoints, and failure isolation.
- Move search/filter/sort/page behavior to the server-side decrypt-and-scan path
  and verify accent-insensitive results against the current behavior.
- Stop plaintext writes only after API, workflow, ARCO, audit, and search tests
  pass; remove plaintext after an approved rollback window and backup review.

### Phase 3: Private Object Migration

- Add a streaming encrypted-object service shared by internal documents,
  migrant supporting documents, ARCO artifacts, and exports.
- Encrypt new uploads immediately using opaque names and per-object DEKs.
- Backfill existing objects in resumable batches, verify decrypted SHA-256 and
  size against stored plaintext identity, then remove the plaintext object.
- Stream authorized downloads without persistent plaintext intermediates.
- Implement ordinary and optional AES-256 recipient-protected ZIP delivery.
- Confirm signing receipts and verification packages remain valid before and
  after object re-encryption and KEK rotation.

### Phase 4: Key Isolation and Lifecycle

- Add explicit key versioning, activation, rewrap, revocation, and restore drills.
- Move direct KMS permission from Laravel to the independent broker once the
  broker can validate identity, role, resource, workflow, and passkey proof.
- Add bounded key caching with documented TTL, use-count, byte, memory-clearing,
  and emergency-revocation behavior.
- Maintain offline, two-person-controlled recovery instructions and test them.

### Phase 5: Validation and Operations

- Test payload and file round trips, tampering, truncation, ciphertext swapping,
  wrong encryption context, KMS outage, and interrupted rotation.
- Test large-file streaming with bounded memory and no persistent plaintext temp.
- Test registration search parity, pagination, ARCO access/rectification/
  cancellation, document downloads, passkey gates, and bulk-operation limits.
- Test standard ZIP and AES-256 ZIP clients, one-time passphrase handling, and
  absence of passphrases or plaintext from logs, queues, errors, and monitoring.
- Test backup restore while every referenced historical KMS key remains usable.
- Alert on abnormal bulk reads, exports, decryptions, broker denials, and key use.
- Reassess the threat model, provider attestations, cache thresholds, and KMS
  spend at least annually.

## Acceptance Gates

Application encryption is not complete until all of these are true:

- A raw database dump exposes no registration questionnaire or ARCO payload plaintext.
- A private-storage copy exposes no document, supporting file, or persisted archive plaintext.
- Existing accent-insensitive search and workflow filters pass parity tests.
- Existing signature and ARCO hashes and receipts remain verifiable.
- 512 MB uploads and downloads complete with bounded memory use.
- Tampered, truncated, swapped, or wrong-context ciphertext fails closed.
- KMS denial or outage produces a controlled error and never a plaintext fallback.
- Migration can resume safely and rollback without silently losing writes.
- Key-use and bulk-decryption events are auditable without logging sensitive data.
- A tested recovery procedure can restore encrypted backups and all dependent keys.

## References

- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [OWASP Key Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html)
- [OWASP Database Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Database_Security_Cheat_Sheet.html)
- [NIST SP 800-122, Protecting the Confidentiality of Personally Identifiable Information](https://csrc.nist.gov/pubs/sp/800/122/final)
- [AWS KMS pricing](https://aws.amazon.com/kms/pricing/)
- [AWS KMS least-privilege guidance](https://docs.aws.amazon.com/kms/latest/developerguide/least-privilege.html)
- [AWS Encryption SDK hierarchical keyring](https://docs.aws.amazon.com/encryption-sdk/latest/developer-guide/use-hierarchical-keyring.html)
- [AWS data-key caching thresholds](https://docs.aws.amazon.com/encryption-sdk/latest/developer-guide/thresholds.html)
- [AWS KMS endpoints and quotas](https://docs.aws.amazon.com/general/latest/gr/kms.html)
- [Ley Federal de Proteccion de Datos Personales en Posesion de los Particulares](https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf)

## Key-Loss Warning

Encryption keys are part of the recovery system. Losing the only private backup
identity or managed encryption key permanently destroys access to the data.
Maintain controlled, tested, offline recovery copies with documented custody.
