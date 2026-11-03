# Tablero de estadísticas de registros

Visualización agregada de los registros de migrantes: nacionalidad, nivel educativo, género, llegadas por mes,
lectoescritura, tiempo de México a Monterrey, destino final y estado civil, todo filtrable por fecha.

- **Fecha:** 2026-10-16
- **Rama:** `feature/registry-statistics` (desde `main`, `f54ecff`)
- **Ruta web:** `/app/migrants/statistics` (menú *Espacio de migrantes → Estadísticas*)
- **Endpoint:** `GET /registry/migrants/statistics?from=YYYY-MM-DD&to=YYYY-MM-DD`

## 1. Decisiones acordadas

| Pregunta | Decisión |
|---|---|
| "Ingresos por mes" | **Llegadas por mes** (el cuestionario no tiene pregunta de ingresos económicos). |
| Llegada a Monterrey | No existe la pregunta; se usa la **Fecha de atención** en Casa Monarca. |
| Quién lo ve | **Admin y coordinador.** |
| Qué registros cuentan | **Solo aprobados** (excluye borradores, en revisión, rechazados y borrados). |
| "Todo por fecha" | Un filtro de rango por **Fecha de atención** aplica a todo el tablero. Si un registro no tiene fecha de atención válida, se usa su fecha de creación. |

## 2. De dónde sale cada dato

| Visualización | Pregunta(s) del cuestionario `migrant-intake-v2` | Tratamiento |
|---|---|---|
| Nacionalidad | Q7 País de origen | Top 10 por cantidad; el resto se agrupa en "Otros países (n)". |
| Nivel educativo | Q28 (adultos) + Q29 (menores) | Se unen; orden fijo de *Sin escolarizar* a *Posgrado*. |
| Género | Q6 | Por cantidad. |
| Sabe leer y escribir | Q26 Sí/No | Indicador (% de quienes respondieron) + barras. |
| Llegadas por mes | Q1 Fecha de atención | Meses continuos; un mes sin llegadas aparece en 0. |
| México → Monterrey | Q39 Fecha en que ingresó a México → Q1 | Días entre ambas fechas: mediana, rango intercuartil e histograma de 8 intervalos. Se excluyen y se reportan los registros sin alguna de las fechas y los que tienen la atención **antes** del ingreso (dato inconsistente). |
| Destino final | Q41 | Se quita el punto final de las opciones ("Estados Unidos." → "Estados Unidos"). |
| Estado civil | Q9 | Por cantidad. |

Respuestas vacías cuentan como **"Sin respuesta"**, siempre al final y en gris. Los registros con el formato anterior
(sin `questionnaire.answers`) se convierten con `upgradeLegacyPayload()` antes de contarse.

## 3. Diseño

### Estructura de la página

1. **Filtros (una sola fila arriba):** rango de fecha de atención (últimos 30 días, 3 meses, 12 meses, todo,
   personalizado) y "Desglose mensual por" (género, destino final, lectoescritura). Todo lo de abajo usa el mismo rango.
2. **Resumen (cifras):** personas que llegaron (cifra principal), mediana de días México → Monterrey,
   % que sabe leer y escribir, destino final más común.
3. **Llegadas por mes:** columnas.
4. **Llegadas por mes según…:** columnas apiladas por la dimensión elegida.
5. **Tiempo de México a Monterrey:** histograma.
6. **Seis distribuciones:** barras horizontales con cantidad y porcentaje.

### Reglas de visualización aplicadas

- **Paleta validada** con `validate_palette.js` sobre la superficie real de los paneles (`#fffcf7`): pasan banda de
  luminosidad, croma, separación para daltonismo (ΔE 9.1) y visión normal (ΔE 22.9). Aqua y amarillo quedan bajo
  3:1 de contraste, por eso cada gráfica lleva leyenda o etiquetas directas **y** una tabla.
- **Una serie = un color.** Las distribuciones usan solo el azul; "Sin respuesta" y "Otros" van en gris.
- **El color sigue a la categoría.** En el desglose apilado, cada categoría tiene un color fijo según el orden del
  cuestionario, así que cambiar el rango de fechas no recolorea. Por eso el desglose solo se ofrece para dimensiones
  de hasta 4 categorías conocidas (género, destino, lectoescritura); nacionalidad, educación y estado civil tienen
  demasiadas para apilarse sin confundir.
- **Marcas:** columnas de máximo 24 px, extremo superior redondeado (4 px) y base recta, 2 px de separación entre
  segmentos apilados, cuadrícula de líneas finas sólidas, un solo eje.
- **Etiquetas selectivas:** solo la columna más alta y la última llevan número; el resto está en el eje, el tooltip y
  la tabla.
- **Interacción:** cada columna responde a mouse **y teclado** (Tab) con un tooltip que lista todas las series y el
  total; las demás columnas se atenúan. Las barras horizontales son enfocables y tienen etiqueta accesible.
- **Tabla gemela:** cada gráfica tiene "Ver como tabla" con todos los valores.
- **Recarga sin parpadeo:** al cambiar el filtro se mantiene la vista anterior al 55% de opacidad hasta que llegan
  los datos nuevos.
- **Responsivo:** a 375 px no hay desplazamiento horizontal; las gráficas se ajustan al ancho y espacian las
  etiquetas de mes.
- Sin librerías nuevas: SVG y CSS propios.

## 4. Archivos

| Archivo | Función |
|---|---|
| `API/app/Services/Registry/MigrantRegistryStatisticsService.php` | Agregación en PHP sobre los payloads decodificados. |
| `API/app/Http/Controllers/Api/Registry/MigrantRegistryStatisticsController.php` | Valida `from`/`to` y responde. |
| `API/routes/api.php` | Ruta en el grupo `requireRole:admin,coordinator` (con `requireSecurityEnrollment`). |
| `API/tests/Feature/Api/Registry/MigrantRegistryStatisticsApiTest.php` | 7 pruebas. |
| `Web/src/lib/registryStatistics.ts` | Tipos y llamada al API. |
| `Web/src/components/charts/ColumnChart.tsx` | Columnas simples y apiladas en SVG, con tooltip. |
| `Web/src/components/charts/BarList.tsx` | Barras horizontales. |
| `Web/src/components/charts/DataTable.tsx` | Tabla gemela desplegable. |
| `Web/src/components/charts/chartFormat.ts` | Formato de números, porcentajes, meses y ejes. |
| `Web/src/components/charts/charts.css` | Variables de color validadas y estilos. |
| `Web/src/pages/registry/MigrantStatisticsPage.tsx` | La página. |
| `Web/src/pages/registry/MigrantStatisticsPage.test.tsx` | 3 pruebas. |
| `Web/src/config/appRoutes.ts`, `Web/src/pages/AppShellPage.tsx`, `Web/src/components/ui/AppIcon.tsx` | Ruta, menú e ícono `chart`. |

## 5. Verificación

| Prueba | Resultado |
|---|---|
| API — `MigrantRegistryStatisticsApiTest` | 7 pasan: permisos por rol (operativo y voluntario → 403), solo aprobados, agregación y orden, unión de educación, meses sin llegadas en 0, filtro de rango, días/mediana/intervalos/excluidos, respaldo con fecha de creación, rango inválido → 422. |
| API — suite completa | 201 pasan. |
| Web — `typecheck`, `eslint` | Sin errores. |
| Web — `vitest run` | 45 pasan (3 nuevas). |
| Revisión visual | Con una página temporal de vista previa y datos ficticios (borrada después): escritorio y 375 px, tooltip por mouse y teclado, apilado con separación, sin desbordes. |
| Con sesión real | **No verificado:** el admin local no tiene passkey, y el área de migrantes la exige. |

## 6. Limitaciones y siguientes pasos

1. **Escala.** Cada consulta recorre todos los registros aprobados en PHP. Es lo correcto si se activa el cifrado
   (no se puede filtrar JSON cifrado en SQL), pero con decenas de miles de registros convendrá cachear el resultado
   por rango o precalcular por mes.
2. **Al fusionar con el cifrado** (`feature/registry-payload-encryption`) no hay que cambiar nada: el servicio lee
   `$entry->payload_json` y el cast descifra. Conviene una prueba con cifrado activo.
3. **Grupos pequeños.** Las cifras son agregadas, pero con pocos registros en un rango corto, una combinación
   (p. ej. un país con 1 persona en un mes) puede identificar a alguien. Admin y coordinador ya ven los registros
   completos; si el tablero se abre a más roles, conviene ocultar conteos menores a 5.
4. **Idioma.** Los nombres de las categorías vienen del cuestionario en español; en la interfaz en inglés se
   muestran en español.
5. **Modo oscuro.** La app no tiene tema oscuro; cuando lo tenga, hay que agregar el juego de colores oscuro en
   `charts.css` y validarlo contra esa superficie.
6. **Fecha real de llegada a Monterrey.** Si se agrega esa pregunta al cuestionario, el cálculo puede usarla en lugar
   de la fecha de atención.
