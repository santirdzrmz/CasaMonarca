# Demostración con la interfaz y la base de datos reales (datos sintéticos)

Recorrido para mostrar el modelo de llaves por usuario funcionando de punta a punta: la web, el API y MySQL local, con
datos inventados. Lo que se ve en pantalla depende de las llaves y de los accesos de cada nivel.
Todo es **solo para desarrollo local** (`APP_ENV=local`); los comandos `registry:demo-*` se niegan a correr en otro entorno.

## 1. Antes de empezar

1. Pon tu clon al día con la rama más nueva de tu compañero (pídele la URL de su repositorio y el nombre de la rama):
   `git remote add equipo <URL>`, `git fetch equipo`, `git checkout -b poc/final equipo/<rama>`.
2. Aplica el parche en esa rama (`git apply --check --ignore-whitespace ..\registry-professor-model.patch` y luego sin `--check`).
3. `cd API` y `composer install`; `cd ..\Web` y `npm install`.
4. Extensiones de PHP necesarias: `pdo_mysql`, `openssl`, `mbstring`, **`sodium`**.

## 2. Limpieza de las pruebas de concepto anteriores (poc 1 y poc 2)

Desde `CasaMonarca\API` (PowerShell):

```
Remove-Item -Force -ErrorAction SilentlyContinue config\poc.php, config\poc2.php, app\Console\Commands\PocDemo.php, app\Console\Commands\Poc2Setup.php, app\Console\Commands\Poc2Demo.php, app\Console\Commands\Poc2Leer.php, app\Console\Commands\Poc2Auditoria.php, app\Casts\PocEncrypted.php, database\seeders\PocSeeder.php
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue app\Support\Poc, app\Models\Poc, app\Poc2, database\migrations\poc, storage\keys\poc2
git restore config\database.php
(Get-Content .env) | Where-Object { $_ -notmatch '^POC_' } | Set-Content .env
```

En MySQL (`mysql.exe -u root -p`): `DROP DATABASE IF EXISTS casamonarca_poc;` y `DROP USER IF EXISTS 'poc_admin'@'localhost';`.
Después de cambiar de rama, borra la antigua: `git branch -D poc/cifrado-por-campo`.

## 3. Puesta en marcha

Desde `CasaMonarca\API`:

```
php artisan migrate:fresh            # base LOCAL vacía (borra TODAS las tablas de casamonarca_api)
php artisan registry:demo-setup      # 4 usuarios (uno por nivel), llaves de dominio y archivos de llave
php artisan db:seed --class=MigrantRegistryDemoSeeder   # registros en todos los estados y solicitudes ARCO
php artisan registry:synthetic --count=200 --seed=2026 --force   # volumen para el dashboard
```

Los datos se crean primero en claro y después se cifran con el backfill, igual que se haría con datos existentes.
`registry:demo-setup` imprime las **llaves públicas**. Edita (no dupliques) estas líneas de `API/.env`:

```
REGISTRY_ENCRYPTION_ENABLED=true
REGISTRY_ENCRYPTION_MODE=publickey
REGISTRY_ENCRYPTION_WRITE_PLAINTEXT=true
REGISTRY_PK_CAND_ID=pk:cand-1
REGISTRY_PK_CAND_PUBLIC=<la que imprimió>
REGISTRY_PK_PROC_ID=pk:proc-1
REGISTRY_PK_PROC_PUBLIC=<la que imprimió>
REGISTRY_SESSION_KEY_TTL=3600
REGISTRY_VISIBILITY_ENFORCED=true
```

Luego:

```
php artisan config:clear
php artisan registry:encrypt-backfill --dry-run --private-dir=storage\app\demo-keys\private
php artisan registry:encrypt-backfill --private-dir=storage\app\demo-keys\private
php artisan registry:encrypt-backfill --purge-plaintext --private-dir=storage\app\demo-keys\private
```

Cuando termine, pon `REGISTRY_ENCRYPTION_WRITE_PLAINTEXT=false` y `php artisan config:clear`. En MySQL, `payload_json` ya solo
tiene marcadores y `payload_json_enc` tiene el sobre `{kek: "pk:cand-1" o "pk:proc-1", wk, ct}`.

Arranque (dos terminales): `php artisan serve --host=127.0.0.1 --port=8000` en `API` y `npm run dev` en `Web`
(con `Web/.env`: `VITE_API_BASE_URL=/api` y `API_PROXY_TARGET=http://127.0.0.1:8000`). Abre **http://localhost:5173**
(WebAuthn exige `localhost`, no una IP).

## 4. Usuarios

| Nivel | Correo | Contraseña |
|---|---|---|
| 4 Voluntario | voluntario@demo.test | DemoPassword-2026! |
| 3 Operador | operador@demo.test | DemoPassword-2026! |
| 2 Coordinador | coordinador@demo.test | DemoPassword-2026! |
| 1 Administrador | admin@demo.test | DemoPassword-2026! |

- Código TOTP: `php artisan registry:demo-totp <correo>` (sin app de autenticación).
- Coordinador y administrador deben además registrar una **llave de acceso (passkey)** la primera vez; sirve Windows Hello
  o la YubiKey. Las decisiones de aprobación y ARCO piden esa llave.
- Archivos de llave (coordinador y administrador): `API\storage\app\demo-keys\coordinator.keyfile.json` y `admin.keyfile.json`,
  contraseña del archivo `DemoKeys-2026-local`.

## 5. Guion por nivel (qué debe verse)

Usa un navegador normal para un usuario y una ventana privada para otro, así dos personas pueden estar conectadas a la vez.

| Nivel | Qué hacer | Qué debe pasar |
|---|---|---|
| 4 Voluntario | Crear y enviar un registro | Lo ve en su lista solo como estado. Si llama al API de la lista o el detalle de otro, no recibe contenido |
| 3 Operador | Abrir Aprobaciones sin acceso | Ve su **huella de sesión** y la lista de revisión sin contenido |
| 2 Coordinador | Aprobaciones: ver la solicitud, **desbloquear llaves** (archivo + contraseña) | La barra muestra "Candidatos v1, Procesados v1" y el tiempo restante (60 min) |
| 2 Coordinador | Confirmar la huella que le dicta el operador y conceder 60 min | La solicitud pasa a "Accesos activos" |
| 3 Operador | Actualizar la página | Ahora **sí** ve el contenido de ese registro y de ningún otro; el coordinador puede cerrar sesión |
| 2 Coordinador | Aprobar el registro | Se vuelve a sellar: en MySQL `kek` pasa de `pk:cand-1` a `pk:proc-1` (mismo id) |
| 2 y 1 | Dashboard de estadísticas | Con llaves desbloqueadas muestra las cifras; con **Bloquear llaves** pide desbloquear |
| 3 → 2 → 1 | Derechos ARCO (abajo) | Cada decisión con passkey |
| 1 Administrador | Pantalla de bitácora | Eventos `registry.keys.*` y `registry.access.*` con quién y cuándo |

### Derechos ARCO

La app implementa **acceso, rectificación y cancelación** (`ARCO_ENABLED_TYPES`); **oposición no está implementada**.
El flujo: el Operador crea la solicitud sobre un registro **aprobado**, el coordinador decide, y el administrador da la
decisión final (acceso: genera el documento; rectificación: aplica el cambio; cancelación: borra el registro y sus copias cifradas).

1. El Operador necesita acceso temporal al registro aprobado (el contenido está en el dominio de procesados): lo pide en el panel,
   el coordinador lo concede.
2. El Operador crea la solicitud ARCO; en su lista ve la solicitud, y su contenido solo si tiene acceso.
3. El coordinador (con llaves desbloqueadas) abre la solicitud y decide con su passkey.
4. El administrador (con llaves desbloqueadas) decide con su passkey. En una rectificación el registro se vuelve a sellar con
   la llave de procesados; en una cancelación desaparecen `payload_json_enc` y las copias.

## 6. ¿Todos conectados al mismo tiempo? ¿Cuánto dura el acceso?

- **Las llaves del coordinador** duran 60 minutos desde que las desbloquea (o menos si presiona Bloquear).
- **Un acceso temporal del operador** dura lo que elija el coordinador (30 a 240 minutos; 60 por defecto) y es solo para ese registro.
- **Después de conceder, el coordinador puede desconectarse**: el operador sigue leyendo mientras no venza.
- **En el momento de conceder** sí deben coincidir los dos: el operador debe tener su sesión abierta (su llave de sesión es
  la que recibe el acceso) y el coordinador debe tener sus llaves desbloqueadas. Si el operador cierra sesión, el acceso deja de servir.
- No hace falta que los demás niveles estén conectados.

## 7. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `Call to undefined function sodium_*` | Activa `extension=sodium` en `php.ini` |
| La API responde 423 | Faltan llaves en la sesión: desbloquea con el archivo de llave (coordinador/administrador) |
| `registry:encrypt-backfill` pide `--private-dir` | En modo `publickey` necesita las privadas para verificar cada registro |
| `registry:demo-setup` falla al entregar llaves | Revisa `REGISTRY_PK_*_ID` (deben ser `pk:cand-1` y `pk:proc-1`) y que existan los archivos en `demo-keys\private` |
| No deja registrar la passkey | Usa `http://localhost:5173`, no `127.0.0.1` |
| Los registros nuevos salen en claro | `REGISTRY_ENCRYPTION_ENABLED` está en `false` o falta `config:clear` |

## 8. Cómo volver a empezar

`php artisan migrate:fresh`, y repite desde `registry:demo-setup`. Las llaves de `demo-keys\private` se reutilizan si ya existen;
borra esa carpeta para generar unas nuevas.
