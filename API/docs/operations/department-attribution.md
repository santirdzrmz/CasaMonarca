# Trazabilidad por departamento

Cada modificación en la aplicación queda firmada con el departamento desde el que actúa la persona.

- **Fecha:** 2026-10-13
- **Rama:** `feature/department-attribution` (desde `main`, `f54ecff`)
- **Decisiones acordadas:**
  1. El admin asigna a cada usuario uno o más departamentos; en cada modificación el usuario declara con
     cuál actúa, y el API rechaza uno que no tenga asignado.
  2. Aplica a **todas** las modificaciones.
  3. "Firmar" = declaración obligatoria registrada en la bitácora; en las acciones que ya piden passkey,
     el departamento queda atado a esa firma. No se agregan pasos nuevos.

## 1. Departamentos

| Valor en API | Etiqueta |
|---|---|
| `humanitarian` | Humanitaria |
| `psychosocial` | Psicosocial |
| `legal` | Legal |
| `communication` | Comunicación |
| `warehouse` | Almacén |
| `information_technology` | Tecnologías de información |

Definidos en `app/Enums/Department.php` (API) y `src/lib/departments.ts` (Web).

## 2. Cómo funciona

```
Navegador                                   API
─────────                                   ───
Selector "Departamento con el que actúas"
   │ (se guarda por usuario en sessionStorage;
   │  si tiene uno solo, se elige solo)
   ▼
fetch POST/PUT/PATCH/DELETE ──► X-Acting-Department: legal
                                   │
                                   ▼
                          RequireActingDepartment
                           ├─ ¿falta?          → 422 acting_department_required
                           ├─ ¿no existe?      → 422 acting_department_invalid
                           ├─ ¿no asignado?    → 403 acting_department_not_assigned
                           ├─ ¿verify ≠ options? → 409 acting_department_mismatch
                           │   (cada rechazo → audit_events: auth.acting_department.denied)
                           └─ OK → request.attributes[acting_department]
                                   │
                                   ▼
                          Controlador / servicio (sin cambios)
                                   │
                                   ▼
                          AuditEventService → audit_events.acting_department
```

### Por qué un middleware y un interceptor

- **API:** todas las escrituras de la bitácora pasan por `AuditEventService`. Leer el departamento ahí cubre
  todos los eventos sin tocar los ~40 controladores.
- **Web:** el cliente arma ~60 peticiones en módulos distintos, sin un cliente HTTP central. Un interceptor de
  `fetch` (`installActingDepartmentHeader`) agrega el encabezado a toda petición de modificación hacia el API,
  así ninguna se queda fuera.

### Atadura a la firma con passkey

Las acciones sensibles se hacen en dos pasos: `…/options` emite el reto y `…/verify` recibe la firma. Algunos
flujos guardan el reto en sesión y otros en `security_challenge_intents`. El middleware cubre ambos:

1. En `…/options` exitoso guarda en sesión `acting_department_challenges[<flujo>] = <departamento>`.
2. En `…/verify` exige que el departamento declarado sea el mismo; si no, **409** y evento de rechazo.
3. Los retos guardados en BD llevan además `actingDepartment` en su `payload`, y los eventos de
   expiración/cancelación lo heredan.

Así, el departamento con el que se pidió la firma es el que queda en la acción firmada.

### Qué rutas exigen departamento

| Exige | No exige |
|---|---|
| Toda petición POST/PUT/PATCH/DELETE de los seis grupos autenticados (`routes/api.php`): registros y borradores, documentos de registro, revisiones, aprobaciones, ARCO, documentos internos, firmas, invitaciones, acciones de admin. | Lecturas (GET). Rutas personales: login, logout, inscribir TOTP o passkey, borrar la propia passkey, cancelar un reto. Descargas que usan POST para la passkey (`…/documents/{id}/download/*`, `…/pdf/*`). |

Las rutas personales quedan libres para que un usuario sin departamento todavía pueda iniciar sesión y
configurar su seguridad.

## 3. Pasos aplicados

1. Rama desde `main`: `git checkout -b feature/department-attribution`.
2. API: enum, modelo `UserDepartment`, migración, middleware, cambios en auditoría, endpoint y comando.
3. `php artisan migrate` — crea `user_departments` y la columna `audit_events.acting_department` (indexada).
4. Arranque del primer admin: `php artisan users:departments admin@example.com information_technology`.
5. Web: selector, interceptor, editor en el panel de admin, columna y filtro en la bitácora.

## 4. Archivos

### API — nuevos

| Archivo | Función |
|---|---|
| `app/Enums/Department.php` | Los seis departamentos y sus etiquetas. |
| `app/Models/UserDepartment.php` | Asignación usuario ↔ departamento (con quién la asignó). |
| `app/Http/Middleware/RequireActingDepartment.php` | Valida la declaración, la ata al reto de passkey y audita rechazos. |
| `app/Services/Admin/UserDepartmentAssignmentService.php` | Sincroniza asignaciones y registra `admin.user.departments_changed` (antes, después, agregados, quitados). |
| `app/Http/Controllers/Api/Admin/UserDepartmentsUpdateController.php` | `PUT /admin/users/{user}/departments`. Impide que el admin se quite todos sus propios departamentos. |
| `config/departments.php` | Interruptor `DEPARTMENT_ATTRIBUTION_ENFORCED`. |
| `database/migrations/2026_10_13_000001_create_user_departments_and_audit_department.php` | Tabla y columna nuevas. |
| `tests/Feature/Api/Auth/ActingDepartmentTest.php` | 11 pruebas (ver §6). |

### API — modificados

| Archivo | Cambio |
|---|---|
| `app/Models/User.php` | `departmentAssignments()`, `departmentValues()`, `hasDepartment()`. |
| `app/Models/AuditEvent.php` | `acting_department` en `Fillable`. |
| `app/Enums/AuditEventType.php` | `admin.user.departments_changed`, `auth.acting_department.denied`. |
| `app/Services/Audit/AuditEventService.php` | Escribe `acting_department` desde la petición. |
| `app/Services/Security/SecurityChallengeIntentService.php` | Guarda el departamento en el reto y en sus eventos de ciclo de vida. |
| `app/Services/Auth/AuthenticatedUserViewService.php` | `/me` y login devuelven `departments`. |
| `app/Services/Admin/UserDirectoryViewService.php` | El directorio de admin devuelve `departments`. |
| `app/Http/Controllers/Api/Audit/AuditEventIndexController.php` | `actor.department` en la respuesta y filtro `?department=`. |
| `bootstrap/app.php` | Alias `requireDepartment`. |
| `routes/api.php` | Middleware en los grupos, ruta nueva, exclusión de descargas. |
| `routes/console.php` | Comando `users:departments`. |
| `.env.example` | `DEPARTMENT_ATTRIBUTION_ENFORCED=true`. |
| `phpunit.xml` | `DEPARTMENT_ATTRIBUTION_ENFORCED=false` para que las pruebas existentes no tengan que mandar el encabezado; las nuevas lo activan. |

### Web

| Archivo | Cambio |
|---|---|
| `src/lib/departments.ts` (nuevo) | Lista y etiquetas, estado del departamento activo, interceptor de `fetch`. |
| `src/lib/departments.test.ts` (nuevo) | 5 pruebas del estado y del interceptor. |
| `src/components/ActingDepartmentSelector.tsx` (nuevo) | Selector en la barra lateral; aviso si no tiene departamentos o no ha elegido. |
| `src/components/AdminUserDepartmentsEditor.tsx` (nuevo) | Casillas por usuario en el panel de admin. |
| `src/main.tsx` | Instala el interceptor. |
| `src/App.tsx` | Sincroniza el departamento activo con el usuario **real** (no con el rol simulado de la Rama Dev). |
| `src/pages/AppShellPage.tsx` | Muestra el selector; pasa `onUserUpdated` al admin. |
| `src/pages/AdminPage.tsx` | Usa el editor; si el admin edita sus propios departamentos recarga `/me`. |
| `src/pages/LoggingPage.tsx` | Etiqueta del departamento junto al actor y filtro por departamento. |
| `src/lib/auth.ts`, `src/lib/adminUsers.ts`, `src/lib/auditEvents.ts` | Campo `departments` / `department`, función `updateAdminUserDepartments`, parámetro de filtro. |
| `src/App.css` | Estilos del selector, editor y etiqueta. |

Total: 34 archivos, +1353 / −13 líneas (sin contar este documento).

## 5. Uso

**Usuario:** en la barra lateral, "Departamento con el que actúas". Con un solo departamento asignado se elige
solo. Sin departamento, la app muestra un aviso y el API rechaza cualquier cambio.

**Admin:** Panel de administración → tarjeta del usuario → "Departamentos" → marcar → "Guardar departamentos".

**Consola** (arranque o emergencias):

```bash
php artisan users:departments <email>                         # ver los actuales
php artisan users:departments <email> legal warehouse         # agregar
php artisan users:departments <email> legal --replace         # reemplazar
```

**Bitácora:** cada evento muestra el departamento junto al rol; se puede filtrar por departamento.

## 6. Verificación

| Prueba | Resultado |
|---|---|
| Web — `npm run typecheck` | Sin errores. |
| Web — `eslint` en archivos tocados | Sin errores. |
| Web — `vitest run` | 15 archivos, 47 pruebas pasan (incluye las 5 nuevas). |
| API — prueba HTTP contra el servidor local con un admin temporal (borrado al terminar) | Sin departamento → 422 `acting_department_required`; `finance` → 422 `acting_department_invalid`; `legal` no asignado → 403 `acting_department_not_assigned`; TI asignado → pasa al controlador y el evento `admin.user.role_changed` queda con `acting_department = information_technology`; GET y logout sin departamento → 200. Los tres rechazos quedaron como `auth.acting_department.denied`. |
| API — `ActingDepartmentTest` (11 pruebas) y suite completa | **Pendiente:** requiere la base `casamonarca_api_test` (ver §7). |
| UI con sesión iniciada | **No verificada en navegador:** el admin local tiene TOTP y el inicio de sesión necesita el código del teléfono. La app carga sin errores en la pantalla de login. |

## 7. Pendientes y riesgos

1. **Correr la suite del API.** Crear la base de pruebas como root y luego `php artisan test`:
   ```sql
   CREATE DATABASE casamonarca_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON casamonarca_api_test.* TO 'casamonarca'@'localhost';
   ```
2. **Despliegue.** Con `DEPARTMENT_ATTRIBUTION_ENFORCED=true`, todo usuario existente sin departamento queda
   bloqueado para hacer cambios. Orden sugerido: desplegar con `false` → asignar el primer admin por consola
   → asignar al resto desde el panel → cambiar a `true`.
3. **Es una declaración, no una prueba criptográfica.** El API garantiza que el departamento esté asignado y
   que coincida entre reto y firma; no puede saber si la persona realmente trabaja en ese momento para ese
   departamento. Para voluntarios y operativos (sin passkey) la declaración se respalda solo en su sesión.
4. **Los registros de firma** (`migrant_registry_signatures`, `document_signatures`, `migrant_arco_signatures`)
   no tienen columna propia de departamento; el departamento está en el evento de auditoría de la misma acción.
   Si se necesita en el PDF o en el paquete de verificación, hay que agregarlo ahí.
5. **Eventos anteriores** a este cambio tienen `acting_department = NULL`.
6. **Interceptor de `fetch`.** Si en el futuro se usa otro cliente HTTP (axios, XHR), hay que agregar el
   encabezado ahí también.

## 8. Cómo revertir

```bash
php artisan migrate:rollback --step=1   # quita user_departments y audit_events.acting_department
git checkout main
```

Para desactivar sin revertir: `DEPARTMENT_ATTRIBUTION_ENFORCED=false` y `php artisan optimize:clear`.
