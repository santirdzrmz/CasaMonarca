# Datos sintéticos de registros

`php artisan registry:synthetic` llena una base **de desarrollo** con registros de migrantes inventados para probar
pantallas, flujos y el tablero de estadísticas sin usar información de personas reales.

- **Rama:** `feature/synthetic-registry-data` (desde `main`)
- **Archivos:** `app/Console/Commands/RegistrySyntheticData.php`,
  `app/Services/Registry/SyntheticMigrantRegistryGenerator.php`,
  `tests/Feature/Console/RegistrySyntheticDataTest.php`

## Uso

```bash
php artisan registry:synthetic                      # 600 registros en 13 meses (pide confirmación)
php artisan registry:synthetic --count=200 --months=6
php artisan registry:synthetic --seed=2026 --force  # conjunto reproducible, sin pregunta
php artisan registry:synthetic --purge              # borra TODOS los sintéticos (y solo esos)
```

## Protecciones para no mezclar con datos reales

| Protección | Cómo |
|---|---|
| Nunca en producción | Si `APP_ENV=production` el comando termina con error, también con `--purge`. No hay forma de saltarlo. |
| Confirmación | Muestra la base de datos y el entorno y pide confirmar (salvo `--force`). |
| Dueño identificable | Todos los registros los crea `synthetic-data@example.test` (dominio reservado `.test`), una cuenta **suspendida** con contraseña aleatoria que nadie conoce. |
| Datos visiblemente falsos | Apellidos `Sintético Prueba-0001…`, teléfono `0000000000`, textos libres `DATO SINTÉTICO`, observaciones finales "No corresponde a una persona real" (cuando la rama del cuestionario llega a esa pregunta). |
| Marca en el payload | `payload_json.synthetic = {"generator": "registry:synthetic", "version": 1}`. |
| Purga acotada | `--purge` borra solo lo que pertenece a la cuenta sintética, incluyendo ARCO, documentos y archivos creados al probar sobre esos registros, y luego la cuenta. |

Antes de pasar una base de desarrollo a producción (o de restaurar un respaldo de desarrollo), correr `--purge`.

## Cómo se generan

- Recorre el cuestionario `migrant-intake-v2` **siguiendo sus ramas** y responde cada pregunta alcanzable.
- Cada registro se valida con `normalizePayload(..., complete: true)`, la misma regla que un registro enviado:
  opciones válidas, preguntas obligatorias y coherencia entre fecha de nacimiento, edad y grupo de población.
- Proporciones **inventadas pero plausibles** (no son estadísticas de nadie):

| Dato | Distribución aproximada |
|---|---|
| Grupo de población | Adultos 70 %, niñas/niños acompañados 16 %, adolescentes 9 %, adultos mayores 3 %, NNA no acompañados 2 % |
| País | Honduras 30 %, Venezuela 18 %, Guatemala 14 %, El Salvador 9 %, Haití 7 %, Cuba 5 % y otros |
| Género | Coherente con el grupo en menores; en adultos M 54 %, F 40 %, LGBTIQ+ 4 %, NB 2 % |
| Escolaridad | Adultos centrados en primaria/secundaria; menores según edad |
| Lectoescritura | ~87 % sí; menores de 6 años, no |
| Destino final | EE. UU. 60 %, México 35 %, regresar 5 % |
| Días México → atención | Sesgada a la derecha, mediana ~3 semanas, máximo 540 |
| Llegadas | Repartidas en los últimos N meses con variación estacional |
| Estado | Aprobados 85 %, en revisión 6 %, en aprobación 5 %, rechazados 4 % |

- Los registros no generan eventos de auditoría ni firmas: el historial de estado lleva una sola fila
  `DATO SINTÉTICO: generado por registry:synthetic`.
- Con el cifrado de registros activado, se cifran igual que cualquier registro, porque se guardan con el modelo.

## Base local actual

Se cargaron 600 registros con `--seed=2026` (514 aprobados, 37 en revisión, 26 en aprobación, 23 rechazados).

## Verificación

- `RegistrySyntheticDataTest` (4 pruebas): payloads completos y válidos y marcados; misma semilla = mismos datos;
  la purga respeta un registro real; se niega en producción.
- Suite completa del API: 198 pasan.
