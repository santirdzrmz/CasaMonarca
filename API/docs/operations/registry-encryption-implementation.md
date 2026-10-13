# Implementación: cifrado de registros de migrantes (Fase 2)

Bitácora de la aplicación del demo de `playground/` (repositorio OneDrive) al API real, en la rama
`feature/registry-payload-encryption`. Complementa a
[`data-at-rest-encryption.md`](data-at-rest-encryption.md), que define el diseño y las fases.

- **Fecha:** 2026-10-13
- **Base:** `main` en `f54ecff`
- **Alcance:** Fase 2 del roadmap — cifrado de payloads de registro y de ARCO en la base de datos.
- **Fuera de alcance:** archivos en disco (Fase 3), AWS KMS, búsqueda en servidor, borrado de texto plano.

## 1. Qué problema resuelve

Antes de este cambio, `migrant_registry_entries.payload_json` (el cuestionario completo del migrante) y las
copias de ARCO se guardaban como JSON en texto plano. Un respaldo, un dump o una fuga de credenciales de MySQL
exponía todos los datos personales.

Con el cambio, cada payload se guarda además (o solo) como un **sobre cifrado** en una columna `*_enc`.

## 2. Cómo funciona

Cifrado de sobre (envelope encryption) con AES-256-GCM, en dos capas:

```
payload (JSON del cuestionario)
   │  cifrado con una DEK aleatoria de 256 bits (nueva en cada escritura)
   ▼
ct  = AES-256-GCM(DEK, payload, AAD)
wk  = AES-256-GCM(KEK, DEK, AAD)          ← la DEK se guarda "envuelta" por la KEK
   │
   ▼
payload_json_enc = {"v":1,"alg":"A256GCM","kek":"registry-2026-10","wk":"…","ct":"…"}
```

- **DEK** (data encryption key): una por payload, nunca se guarda en claro; se borra de memoria tras usarse.
- **KEK** (key encryption key): llave maestra, vive en un archivo **fuera del repo y del web root**.
  El sobre guarda el id de la KEK, así que una KEK nueva no rompe sobres viejos (rotación).
- **AAD** (datos autenticados): `casamonarca|<entorno>|<tabla>|<id>|<propósito>|v1`.
  Un sobre copiado a otro registro, otra columna, otra tabla u otro entorno **no se puede abrir**.
- **Falla cerrada:** si el sobre está alterado, truncado o falta la llave, se lanza
  `EncryptionException`. Nunca se regresa al texto plano.

### Lectura y escritura en los modelos

- Al **leer**, el cast `EncryptedPayload` usa `*_enc` si existe; si no, el JSON en claro
  (lectura doble durante la migración).
- Al **escribir**, el cast limpia el sobre viejo y el trait `HasEncryptedPayloads` sella el nuevo en el
  evento `saved` (necesita el `id` del registro para el AAD).
- Las columnas `*_enc` están en `$hidden`: no salen en respuestas JSON del API.
- El resto del código (`MigrantRegistryService`, `MigrantArcoService`, controladores) **no cambió**:
  sigue usando `$entry->payload_json` como antes.

### Columnas cubiertas

| Tabla | Columna en claro | Columna cifrada | Propósito (AAD) |
|---|---|---|---|
| `migrant_registry_entries` | `payload_json` | `payload_json_enc` | `registration_payload` |
| `migrant_registry_entries` | `pending_payload_json` | `pending_payload_json_enc` | `pending_registration_payload` |
| `migrant_arco_requests` | `original_payload_json` | `original_payload_json_enc` | `arco_original_payload` |
| `migrant_arco_requests` | `proposed_payload_json` | `proposed_payload_json_enc` | `arco_proposed_payload` |

## 3. Interruptores de configuración

| Variable | Valor local actual | Significado |
|---|---|---|
| `REGISTRY_ENCRYPTION_ENABLED` | `true` | Sellar sobres en cada escritura. `false` = comportamiento original. |
| `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT` | `true` | Escritura doble: conserva también el JSON en claro. `false` = la columna en claro queda en `{}` / `NULL`. |
| `REGISTRY_KEK_ID` | `registry-2026-10` | KEK activa para cifrar. Las anteriores siguen sirviendo para leer. |
| `REGISTRY_KEY_DIRECTORY` | `C:/Users/santi/.casamonarca-keys` | Carpeta con un archivo `<id>.key` por KEK. |
| `REGISTRY_ENCRYPTION_ENV` | (usa `APP_ENV`) | Entra en el AAD: un sobre de `local` no abre en `production`. |

Estados de despliegue, en orden:

1. **Apagado** — `ENABLED=false`. Nada cambia (estado por defecto de `.env.example`).
2. **Escritura doble** — `ENABLED=true`, `WRITE_PLAINTEXT=true`. Estado local actual. Permite volver atrás sin pérdida.
3. **Solo cifrado** — `WRITE_PLAINTEXT=false` y backfill con `--purge-plaintext`. Solo después de
   respaldo cifrado reciente y ventana de rollback aprobada.

## 4. Pasos aplicados

Todos los comandos desde `C:\Users\santi\CasaMonarca`.

### Paso 1 — Rama

```bash
git checkout -b feature/registry-payload-encryption
```

### Paso 2 — Copiar archivos nuevos y aplicar el parche

```bash
cp -r <OneDrive>/CasaMonarca/playground/api-encryption/. API/
git apply <OneDrive>/CasaMonarca/playground/api-changes.patch
```

`git apply --check` pasó sin conflictos (misma base `f54ecff`).

### Paso 3 — Carpeta de llaves

```powershell
New-Item -ItemType Directory C:\Users\santi\.casamonarca-keys
icacls C:\Users\santi\.casamonarca-keys /inheritance:r /grant:r "santi:(OI)(CI)F"
```

Fuera del repositorio, del web root y de `AppData` (la app de escritorio de Claude virtualiza
escrituras nuevas en `AppData\Local`). En Windows `chmod 600` no aplica; el equivalente es quitar herencia y
dejar acceso solo al usuario.

### Paso 4 — Variables en `API/.env`

```dotenv
REGISTRY_ENCRYPTION_ENABLED=true
REGISTRY_ENCRYPTION_WRITE_PLAINTEXT=true
REGISTRY_KEK_ID=registry-2026-10
REGISTRY_KEY_DIRECTORY=C:/Users/santi/.casamonarca-keys
```

### Paso 5 — Migración y KEK

```bash
php artisan optimize:clear
php artisan migrate
php artisan registry:kek-generate registry-2026-10
```

La migración solo **agrega** columnas `*_enc` (nullable). No toca datos existentes.
`kek-generate` nunca sobrescribe una llave existente.

### Paso 6 — Protección de la base de desarrollo en pruebas

Sin `API/.env.testing`, `php artisan test` usaba `API/.env` y las pruebas con `RefreshDatabase`
**borraban `casamonarca_api`**. Se creó `API/.env.testing` (ignorado por git) apuntando a
`casamonarca_api_test`.

## 5. Archivos

### Nuevos

| Archivo | Función |
|---|---|
| `app/Services/Security/Encryption/Aes256Gcm.php` | Primitiva AES-256-GCM (nonce 12 B, tag 16 B) sobre OpenSSL. |
| `app/Services/Security/Encryption/EnvelopeCipher.php` | Formato de sobre, DEK por payload, AAD, validación de versión. |
| `app/Services/Security/Encryption/KekProvider.php` | Interfaz de la KEK; permite cambiar a AWS KMS sin tocar los sobres. |
| `app/Services/Security/Encryption/FileKekProvider.php` | KEK en archivos (provisional, ver §7). |
| `app/Services/Security/Encryption/EncryptionException.php` | Error de falla cerrada. |
| `app/Models/Casts/EncryptedPayload.php` | Cast de lectura doble. |
| `app/Models/Concerns/HasEncryptedPayloads.php` | Sellado tras guardar y purga de texto plano. |
| `app/Console/Commands/RegistryKekGenerate.php` | `registry:kek-generate {id}`. |
| `app/Console/Commands/RegistryEncryptBackfill.php` | `registry:encrypt-backfill [--dry-run] [--batch=] [--purge-plaintext]`, reanudable, verifica descifrando. |
| `config/registry_encryption.php` | Configuración. |
| `database/migrations/2026_10_02_000001_add_encrypted_payload_columns.php` | Cuatro columnas `*_enc`. |
| `tests/Unit/EnvelopeCipherTest.php` | 7 pruebas del cifrado. |
| `tests/Feature/Api/Registry/EncryptedPayloadStorageTest.php` | 4 pruebas contra MySQL. |
| `docs/operations/registry-encryption-implementation.md` | Este documento. |

### Modificados

| Archivo | Cambio |
|---|---|
| `app/Models/MigrantRegistryEntry.php` | Casts `EncryptedPayload`, trait, `$hidden` para `*_enc`. |
| `app/Models/MigrantArcoRequest.php` | Igual, para `original_/proposed_payload_json`. |
| `app/Providers/AppServiceProvider.php` | Registra `KekProvider` y `EnvelopeCipher` como singletons. |
| `app/Services/Registry/MigrantArcoService.php` | La cancelación ARCO también vacía las columnas `*_enc`. |
| `.env.example` | Variables nuevas, apagadas por defecto. |
| `docs/operations/data-at-rest-encryption.md` | Nota de estado en la Fase 2. |

Total: 19 archivos, +848 / −5 líneas (sin contar este documento).

## 6. Verificación

### Pruebas unitarias — 7/7

```
php vendor/bin/phpunit tests/Unit/EnvelopeCipherTest.php --testdox
 ✔ Round trip preserves unicode payload
 ✔ Every encryption uses fresh dek and nonce
 ✔ Ciphertext cannot be moved to another record purpose table or environment
 ✔ Tampering fails closed
 ✔ Truncated and malformed envelopes fail closed
 ✔ Old envelopes stay readable after active kek changes
 ✔ Missing key never falls back to plaintext
OK (7 tests, 14 assertions)
```

### Prueba de punta a punta sobre la base local

Con datos ficticios, usando `MigrantRegistryService` real (`createDraft`, `submitDraft`, `updateDraft`).
Los registros se borraron al terminar.

| Fase | Resultado |
|---|---|
| A — escritura doble | Fila con JSON en claro **y** sobre `{"v":1,"alg":"A256GCM","kek":"registry-2026-10",…}`. El modelo lee `María José`. `pending_payload_json_enc` se sella. `toArray()` no expone `*_enc`. |
| B — solo cifrado | `payload_json` = `{}`; el texto `Peña` no aparece en ninguna columna; el modelo lo lee. `updateDraft` vuelve a sellar correctamente. |
| B — sobre movido | Copiar el sobre del registro A al B → `EncryptionException: Authentication failed`. |
| C — backfill | Registro creado con cifrado apagado (sin sobre). `--dry-run`: 1 por cifrar. Ejecución: 1 cifrado, 0 fallas, verificado por descifrado. |

### App en ejecución

API y Web levantados después del cambio: `/api/csrf-token` responde a través del proxy de Vite y el log del
API no muestra errores.

## 7. Limitaciones y pendientes

1. **Pruebas de feature sin correr.** `EncryptedPayloadStorageTest` y el resto de la suite necesitan
   `casamonarca_api_test`, que aún no existe. Crear como root:
   ```sql
   CREATE DATABASE casamonarca_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON casamonarca_api_test.* TO 'casamonarca'@'localhost';
   ```
   y luego `php artisan test`. Hasta entonces no está comprobado que las pruebas existentes de registro y
   ARCO pasen con el cast nuevo.
2. **Flujo ARCO no probado de punta a punta.** El backfill recorrió la tabla (0 filas); la rectificación y
   la cancelación con sobres no se ejercitaron.
3. **Escritura doble = texto plano sigue en la BD.** En el estado actual un dump todavía expone los datos.
   La protección real llega con `WRITE_PLAINTEXT=false` + backfill `--purge-plaintext`.
4. **KEK en archivo, no KMS.** Protege contra un dump o fuga de la BD, pero no contra quien comprometa el
   mismo servidor. El diseño pide reemplazarlo por AWS KMS con fecha definida.
5. **Sin trazabilidad de llaves.** No hay eventos de auditoría para creación, uso o rotación de la KEK.
6. **Fase 3 pendiente.** Documentos y ZIPs de ARCO en `storage/app/private` siguen sin cifrar.
7. **Borrado del admin.** `MigrantRegistryService::delete()` hace borrado lógico y conserva el payload
   (ahora también el sobre). No se cambió en esta fase.
8. **Logs de MySQL.** Redo/undo log y binlog siguen sin cifrar; pueden conservar valores anteriores.

## 8. Cómo revertir

```bash
# 1. Apagar sin perder datos (la escritura doble mantiene el texto plano al día)
REGISTRY_ENCRYPTION_ENABLED=false        # en API/.env
php artisan optimize:clear

# 2. Quitar columnas y código
php artisan migrate:rollback --step=1
git checkout main && git branch -D feature/registry-payload-encryption
```

Si ya se purgó el texto plano (`--purge-plaintext`), **no** se puede revertir sin la KEK: primero hay que
reescribir los payloads en claro con el cifrado aún activo.

## 9. Custodia de la llave

`C:\Users\santi\.casamonarca-keys\registry-2026-10.key` es la única forma de leer los sobres. Si se pierde,
los datos cifrados se pierden. No subirla al repositorio ni copiarla al servidor junto con los respaldos.
Para producción: copia offline con custodia de dos personas, o migrar a KMS.
