# Llaves por usuario del registro (modelo de llave pública)

**Es el único esquema de llaves del registro** (decisión de 2026-11). Sustituyó a la KEK simétrica en archivo, que se
eliminó del código junto con `registry:kek-generate` y las propuestas de custodia A a D. Usa el `EnvelopeCipher`: cada
registro tiene su DEK AES-256-GCM, envuelta con la llave **pública** de su dominio; abrirla requiere la llave **privada**.
Se activa con `REGISTRY_ENCRYPTION_ENABLED=true` una vez configuradas las llaves públicas; apagado, los datos se guardan en claro.

## Idea

| | KEK en archivo (eliminada) | Llaves por usuario (actual) |
|---|---|---|
| Datos | DEK por registro, AES-256-GCM | Igual |
| La DEK se envuelve con | Una KEK simétrica en un archivo del servidor | Una llave **pública** por dominio |
| Quién puede escribir | El servidor | El servidor (la pública no es secreta) |
| Quién puede leer | El servidor, siempre | Solo quien tenga la llave **privada** del dominio en su sesión, o un acceso temporal a ese registro |

Dominios de llave:

- `cand` (`pk:cand-N`): registros **antes de aprobar** y las propuestas de modificación (`pending_payload_json`).
- `proc` (`pk:proc-N`): registros **aprobados** y solicitudes ARCO. Al aprobar, el payload se vuelve a sellar con `proc`
  (`HasEncryptedPayloads::resealForKeyDomain`) y nunca regresa a `cand`.

Las privadas **no viven en el servidor**: las genera el administrador en su computadora (`registry:keys-init`), idealmente
cifradas con `age` para su YubiKey, y las entrega a cada coordinador o administrador cifradas con la llave pública de esa persona.

## Quién tiene qué

| Rol | Llaves | Qué puede hacer |
|---|---|---|
| Voluntario | Ninguna | Registrar (el servidor usa solo llaves públicas). No lee lo cifrado |
| Operador | Una **llave de sesión** (`Ks`) que nace al iniciar sesión y muere con ella | Lee **solo** los registros que un coordinador le abrió, por un tiempo limitado |
| Coordinador y administrador | Archivo de llave personal (cifrado + firma) y entregas de dominio | Desbloquean sus llaves, leen, aprueban, ven estadísticas y conceden accesos temporales |
| Administrador con YubiKey | Llaves privadas de dominio (`.age`) | Entrega, rota y recupera llaves |

## Accesos temporales del Operador

1. El operador abre su sesión de acceso (`GET /registry/access/mine`): se crea su par `Ks` y se registra su llave pública
   con una **huella** de 12 caracteres. La privada solo existe en el caché, cifrada, mientras dura la sesión.
2. Pide acceso a **un** registro o solicitud ARCO (`POST /registry/access/requests`).
3. Un coordinador o administrador (con sus llaves desbloqueadas) ve la solicitud, le pide al operador que le lea su huella
   y la confirma (`POST /registry/access/requests/{id}/grant`). El servidor abre la DEK del registro con la llave del
   coordinador, la envuelve para `Ks_pub` y **firma** la concesión (Ed25519, la llave de firma del coordinador) con
   vencimiento (5 a 480 min).
4. En cada petición del operador, `LoadRegistryKeys` verifica la firma contra la llave registrada del firmante, el vencimiento
   y que la concesión sea para **esta** sesión, y deja la DEK en el `DekRing` solo durante esa petición. El
   `EnvelopeCipher` la usa únicamente para ese registro.
5. Se puede revocar (`.../revoke`) o negar (`.../deny`). Al cerrar la sesión, `Ks_priv` desaparece y la concesión ya no abre nada.

Una concesión no sobrevive a un cambio del registro: si se vuelve a cifrar el payload (nueva DEK), hay que pedir otra.

## Desbloqueo de sesión (coordinadores y administradores)

1. El usuario sube su archivo de llave y su contraseña a `POST /registry/keys/unlock` (el archivo contiene sus llaves
   privadas cifradas con Argon2id).
2. El servidor abre **sus** entregas y guarda las llaves de dominio y su llave de firma, cifradas con `APP_KEY`, en el caché,
   ligadas a su sesión y con vencimiento (`REGISTRY_SESSION_KEY_TTL`, 60 min por defecto).
3. `loadRegistryKeys` las carga en el `KeyRing` solo durante la petición y las borra al terminar.
4. `DELETE /registry/keys/unlock` las borra de inmediato; el dashboard muestra qué dominios están desbloqueados y cuánto falta.

Si falta una llave, la lectura falla cerrada con `KeysLockedException` y la API responde **423** (`registry_keys_locked`).

## Trazabilidad

Eventos de auditoría (`AuditEventService`): `registry.keys.unlocked`, `registry.keys.unlock.failed`, `registry.keys.locked`,
`registry.access.requested`, `registry.access.granted`, `registry.access.denied`, `registry.access.revoked` y
`registry.access.used` (cada vez que una petición usa una concesión; máximo 10 registros por petición). Los comandos del
administrador no escriben eventos de auditoría; deja constancia de su ejecución en la bitácora operativa del equipo.

## Comandos

| Comando | Quién y dónde | Qué hace |
|---|---|---|
| `registry:keys-init --private-dir=… --age-recipient=age1…` | Administrador, en su computadora | Genera `pk:cand-N` y `pk:proc-N`; imprime las llaves públicas para el `.env` |
| `registry:keys-enroll <email> --out=… [--replace]` | Administrador, con el usuario presente | Crea el archivo de llave personal (cifrado y firma) y registra las llaves públicas; imprime la huella. Si el usuario ya tiene una llave activa exige `--replace`: la anterior queda en el historial y sus entregas se revocan |
| `registry:keys-grant <email> --fingerprint=… --private-dir=… --age-identity=…` | Administrador | Cifra las llaves privadas de dominio con la llave pública del usuario (vencen en 90 días) |
| `registry:keys-revoke <email>` | Administrador | Revoca la llave y las entregas |
| `registry:keys-rewrap --private-dir=… [--dry-run]` | Administrador | Rotación: vuelve a envolver todas las DEK con la llave activa (`--generation=N+1`) sin tocar el contenido |
| `registry:encrypt-backfill --private-dir=…` | Administrador | Cifra los datos existentes; la verificación de ida y vuelta necesita las llaves privadas |

`keys-grant` exige la **huella** de las llaves públicas del usuario, confirmada con él por otro canal (voz o en persona), para
que un servidor comprometido no pueda sustituir su llave sin que se note. El registro de llaves públicas firmantes
(`registry_user_keys`) cumple el papel de un certificado ligero: si más adelante se quiere una CA con certificados X.509,
sustituye ese registro sin cambiar el resto.

### Historial y bitácora

- `registry_user_keys` guarda solo la llave **vigente** de cada usuario; `registry_user_key_history` guarda **todas** las
  llaves públicas que ha tenido, con su fecha de alta y cuándo y por qué dejaron de valer (`replaced` o `revoked`).
- Cada comando escribe un evento en la bitácora (categoría **Llaves y cifrado del registro**), con `via: console` y el
  usuario del sistema operativo que lo ejecutó:

| Evento | Comando | Datos que guarda |
|---|---|---|
| `registry.keys.domain_generated` | `keys-init` | Ids y llaves **públicas** de dominio, protección de las privadas (`age` / `plain`) |
| `registry.keys.enrolled` | `keys-enroll`, `demo-setup` | Usuario, huella, llaves públicas (cifrado y firma), huella reemplazada, entregas revocadas |
| `registry.keys.granted` | `keys-grant` | Usuario, huella, dominios entregados, vencimiento |
| `registry.keys.revoked` | `keys-revoke` | Usuario, huella, entregas revocadas |
| `registry.keys.rewrapped` | `keys-rewrap` | Llaves activas y conteo de sobres re-envueltos o fallidos |
| `registry.encryption.backfilled` | `encrypt-backfill` | Si se borró el texto plano y cuántos fallaron |
| `registry.keys.unlocked` / `.locked` / `.unlock.failed` | Web | Desbloqueo y bloqueo por sesión |
| `registry.access.*` | Web | Accesos temporales de operadores (pedido, concedido, negado, revocado, usado) |

| `registry.keys.session_generated` | Web (operador) | Huella y llave pública X25519 de la llave de sesión (Ks) |

Los eventos de entrega, desbloqueo y acceso indican el acuerdo de llaves usado (`crypto_box_seal`: Diffie-Hellman
X25519 con llave efímera + XSalsa20-Poly1305) y los de acceso la firma Ed25519 (con el SHA-256 de la firma).
Nunca se registran llaves privadas ni contraseñas de archivos de llave.

### Verificar la trazabilidad: `registry:keys-trace`

Reporte de solo lectura (`--json` para máquina; sale con error si algo no cuadra). No confía solo en la bitácora:
revisa los datos guardados.

- **Llaves de dominio:** la llave pública del `.env` es la misma cuya generación quedó en la bitácora.
- **Llaves personales:** historial completo y que cada huella se derive de sus llaves públicas.
- **Diffie-Hellman:** cada sobre sellado (entregas de llaves y sobres de registros) empieza con una llave pública
  X25519 efímera de 32 bytes; todas deben ser distintas, es decir, un intercambio por sobre. Un sobre copiado de otro
  registro aparece como llave efímera repetida.
- **Accesos temporales:** cada acceso concedido tiene una firma Ed25519 válida de una llave registrada del firmante
  (también de llaves ya reemplazadas). Un acceso alterado aparece como firma inválida.
- **Bitácora:** conteo y fecha del último evento de llaves y accesos.

## Puesta en marcha (en orden)

1. En la computadora del administrador: `registry:keys-init` (con `--age-recipient` de la YubiKey, o `--plain` solo en
   desarrollo). Copia al `.env` las llaves **públicas** que imprime.
2. `php artisan migrate`.
3. Por cada coordinador/administrador: `registry:keys-enroll`, confirmar la huella y `registry:keys-grant`.
4. `REGISTRY_ENCRYPTION_ENABLED=true`, `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT=true`.
5. `registry:encrypt-backfill --dry-run` y luego el real, con `--private-dir` (y `--age-identity`).
6. Probar: un coordinador desbloquea y lee; un voluntario no; un operador pide acceso, un coordinador lo concede y solo entonces
   lo lee; todo queda en la bitácora.
7. Solo después, `--purge-plaintext` y `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT=false`.

## Límites conocidos

- Mientras dura una sesión, el servidor tiene las llaves en memoria y en el caché (cifradas con `APP_KEY`). Un servidor
  comprometido **en vivo** podría usarlas; uno comprometido **en reposo** no obtiene ninguna llave de dominio. Descifrar en el
  navegador evitaría esto, pero complica búsquedas, estadísticas y PDF.
- La llave de sesión del operador (`Ks`) la genera el servidor, no su navegador; la huella confirmada con el coordinador
  protege la sustitución de su llave pública, no que el servidor pueda usar su `Ks_priv`.
- Con `CACHE_STORE=database` las llaves de sesión quedan en la tabla `cache` hasta que vencen.
- Las rutas `/registry/keys/*` y `/registry/access/*` no usan `requireDepartment`.
- Perder **todas** las copias de `pk_*.age` (o su YubiKey) destruye los datos que protegen. Guarda una segunda YubiKey.
- Archivos subidos, exportaciones y el PDF del registro no usan este modelo todavía.
- La integración Laravel (migración, rutas, comandos, `resealForKeyDomain`) debe verificarse con `php artisan test` y en un
  entorno local; las pruebas unitarias incluidas cubren la criptografía.

## Pendiente

- Archivos adjuntos y PDF con llaves por usuario.
- Pantallas de desbloqueo en las demás páginas del registro (hoy: estadísticas y accesos).
- Aprobar/rechazar solicitudes ARCO que el operador creó sin tener que pedir acceso a la solicitud recién creada.
