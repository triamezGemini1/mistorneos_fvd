# Procedimiento de Asignación de Rondas y Evaluación de Rendimiento

## Resumen del flujo operativo (Pasos 1 a 5)

### Paso 1: Inscripciones (en línea y en sitio)

- **En línea:** al publicar el evento, las inscripciones generan registro en `inscritos` con **estatus pendiente** (0 o `'pendiente'`).
- **En sitio:** el módulo `inscribir_sitio` / `inscribir_sitio_save.php` inserta en `inscritos` con **estatus confirmado** (1) directamente (`$estatus = 1` en `inscribir_sitio_save.php`).
- **Conclusión:** Ambas fuentes generan registro en `inscritos`. En línea = pendiente; en sitio = confirmado. **Cumple** con lo establecido.

### Paso 2: Verificación en sitio y no presentes

- **Verificación:** En el módulo **Inscripciones** (`modules/gestion_torneos/inscripciones.php`) el operador puede **Confirmar** o **Retirar** cada inscrito (formulario con `action=cambiar_estatus_inscrito`, estatus 1 = confirmar, 4 = retirar).
- **No presentes:** Los que no se confirman quedan en el registro con estatus **pendiente**. Solo los **confirmados** (estatus = 1 o `'confirmado'`) cuentan para generar rondas (`InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO`).
- **Eliminación de no presentes al generar la 3.ª ronda:** Implementado en `generarRonda()`: cuando se va a generar la **ronda 3**, se llama a `marcarNoPresentesRetiradosAntesRonda3($torneo_id)`. Esa función marca como **retirados** (estatus retirado) a todos los inscritos que siguen en **pendiente** y **no tienen ninguna fila en `partiresul`** (nunca participaron en ronda 1 ni 2). Tras marcar, se revalida que sigan al menos 4 confirmados; si no, se muestra error y no se genera la ronda. Los listados y la generación de rondas posteriores ya no incluyen a esos retirados.

---

## Procedimiento de asignación de rondas (establecido en código)

La lógica está centralizada en **`config/MesaAsignacionService.php`** (torneos individual/parejas) y **`config/MesaAsignacionEquiposService.php`** (modalidad equipos). El controlador **`modules/torneo_gestion.php`** usa `generarRonda()` que delega en el servicio según `tournaments.modalidad`.

### Base para todas las rondas

- **Inscritos que cuentan:** Solo aquellos con **estatus confirmado** (`estatus = 1 OR estatus = 'confirmado'`), obtenidos vía `InscritosHelper::sqlWhereSoloConfirmadoConAlias('i')`.
- **Mínimo:** 4 participantes; si hay menos, no se genera ronda.
- **Mesas:** 4 jugadores por mesa. Los que no completan mesa (resto de total/4, máximo 3) se tratan como **BYE** y se registran en `partiresul` con `mesa = 0`.

---

### Primera ronda (torneo individual por posición)

- **Método:** `generarPrimeraRondaPorPosicion($torneoId)` cuando `modalidad = 1` y no es interclub RR.
- **Orden:** `posi_rnk` del atleta (copiado a `inscritos.posicion` al inscribir) — orden lineal ascendente.
- **Asignación:** Vector ordenado por `posi_rnk`. Se forman parejas consecutivas: (1,2), (3,4), (5,6)… **Primera pasada:** pareja 1 → mesa 1 (A,C), pareja 2 → mesa 2, … hasta mesa *n*. **Segunda pasada:** siguientes parejas del vector, otra vez desde mesa 1 hasta *n* (B,D de cada mesa). Ejemplo 20 mesas / 80 jugadores: mesa 1 = (1,2) vs (41,42); mesa 2 = (3,4) vs (43,44). Sobrantes → retirados/BYE.
- **Dispersión por clubes:** Solo si no aplica modo por posición (interclub u otra estrategia).

---

### Segunda ronda

- **Método:** `generarRondaGreedyPorClasificacion($torneoId, 2)`.
- **Orden (máxima):** Solo **ganados DESC → efectividad DESC → puntos DESC** (`sqlOrderClasificacionEstricta`).
- **Asignación greedy (mesa a mesa):** El mejor disponible encabeza **A** de la siguiente mesa; se recorre el ranking para **C** (primer válido: no fue pareja de A); luego **B** y **D** de la pareja opuesta con las mismas reglas. Ejemplo: si #1 y #2 ya jugaron de pareja, #3 va de compañero de #1 en mesa 1 y #2 pasa a ser **A** de mesa 2.
- **Restricciones:** (1) No repetir pareja en todo el historial. (2) No enfrentar en la misma mesa a quienes fueron **compañeros ganadores en R1** (cruzados A–B, A–D, C–B, C–D).
- **Cierre:** `finalizarAsignacionRespetandoHistorial` completa sobrantes e intercambia si hace falta.

---

### Rondas intermedias (3 a N−1)

- **Método:** `generarRondaIntermedia` → mismo greedy G/E/P; **solo** no repetir pareja (sin restricción de enfrento R1).

---

### Última ronda (N)

- **Método:** `generarRondaPatronIntercalado($torneoId, $numRonda)`.
- **Orden:** Clasificación estricta G/E/P.
- **Patrón:** 1+3 vs 2+4 fluido por bloques de 4 en el ranking (sin importar parejas anteriores).
- **BYE:** Sobrantes según reglas habituales del servicio.

---

### Regla BYE (resumen)

- En **`aplicarBye()`** (`MesaAsignacionService.php`):
  - Se insertan filas en `partiresul` con `mesa=0`, `secuencia=1`, `registrado=0`.
  - Se actualiza: `resultado1 = puntos_torneo` (100%), `resultado2 = 0`, `efectividad = 50%` del puntaje del torneo, `registrado = 1`.
  - No hay columna “ganados” en `partiresul`; la agregación en `actualizarEstadisticasInscritos()` interpreta `resultado1 > resultado2` como partida ganada. **Cumple** con lo establecido (ganados, efectividad, resultado1).

---

## Paso 4: Actualización de estadísticas

**Norma del procedimiento:** La actualización de estadísticas tiene como **base** la tabla **`partiresul`** y como **llave** **(id_usuario, id_torneo)** para actualizar la tabla **`inscritos`**. No se usa ninguna otra fuente; los totales (ganados, perdidos, efectividad, puntos, sanciones, etc.) se calculan únicamente desde `partiresul` y se escriben en `inscritos` por esa llave. Los procedimientos están asignados y funcionando según esta regla.

- **Función:** `actualizarEstadisticasInscritos($torneo_id)` en `torneo_gestion.php`.
- **Base:** tabla `partiresul` (registrado = 1). **Llave de actualización:** (id_usuario, id_torneo). **Destino:** tabla `inscritos`.
- **Momento:** Se invoca **antes** de generar la siguiente ronda (ver Paso 5), al guardar resultados, al abrir el reporte de posiciones y desde el botón manual del panel.
- **Lógica:**
  1. Elimina duplicados en `partiresul` (una sola fila por `id_torneo`, `id_usuario`, `partida`).
  2. **No** se inicializa a 0 toda la tabla `inscritos`. Se actualiza con totales desde `partiresul` solo para quienes tienen partidas (`registrado = 1`); los inscritos que no tienen ninguna partida registrada sí se ponen a 0.
  3. Suma por (id_usuario, id_torneo) desde `partiresul` donde `registrado = 1` (incluye mesas y BYE): ganado, perdido, efectividad, puntos, sanciones, etc., usando `MAX` por partida para una fila por jugador por ronda.
  4. UPDATE con JOIN: asigna esos totales a los inscritos que aparecen en la agregación.
  5. UPDATE con LEFT JOIN: pone a 0 (ganados, perdidos, efectividad, puntos, sancion, chancletas, zapatos, tarjeta) solo a los inscritos del torneo que no tienen ninguna fila en `partiresul` con `registrado = 1`.
  6. Si es modalidad equipos, ejecuta `recalcularClasificacionEquiposYJugadores()` (posiciones, estadísticas de equipos).

**Conclusión:** La sumatoria de campos computables de `partiresul` (incluidos BYE) por usuario/torneo y su escritura en `inscritos` está implementada correctamente.

---

## Cómo funciona la actualización de estadísticas (detalle)

- **Base:** tabla **`partiresul`** (única fuente para los resultados de partidas).
- **Llave de actualización:** **(id_usuario, id_torneo)**. Toda la agregación se hace por esta llave y el resultado se escribe en **`inscritos`** (id_usuario, torneo_id).
- La tabla **`inscritos`** guarda los **totales por jugador y torneo** (ganados, perdidos, efectividad, puntos, sanciones, etc.) y la **posición** en la clasificación. Esos totales se recalculan siempre desde `partiresul` cada vez que se ejecuta el procedimiento de actualización de estadísticas.

### Cuándo se ejecuta

1. **Antes de generar la siguiente ronda** — Dentro de `generarRonda()` se llama a `actualizarEstadisticasInscritos($torneo_id)` para que la clasificación y los totales estén al día antes de asignar mesas.
2. **Al guardar resultados de una ronda** — Tras registrar resultados en las mesas, se invoca la misma función para refrescar totales y posiciones.
3. **Manual desde el panel** — El usuario puede pulsar “Actualizar estadísticas” en el panel del torneo; eso llama a `actualizarEstadisticasManual()`, que a su vez ejecuta `actualizarEstadisticasInscritos($torneo_id)`.

### Flujo de `actualizarEstadisticasInscritos($torneo_id)`

**Paso 1 – Limpieza de duplicados en `partiresul`**  
- Debe haber **una sola fila** por combinación `(id_torneo, id_usuario, partida)`.  
- Se buscan grupos con más de una fila para esa combinación; se conserva la de `MIN(id)` y se **borran** el resto.  
- Así se evita contar dos veces la misma partida para un jugador.

**Paso 2 – No inicializar a 0 toda la tabla**  
- **No** se hace un UPDATE que ponga a cero a todos los inscritos del torneo.  
- Solo se actualizarán con totales desde `partiresul` (paso 3–4) o se pondrán a 0 si no tienen partidas (paso 5).

**Paso 3 – Agregación desde `partiresul`**  
- Solo se consideran filas con **`registrado = 1`** (partida ya registrada, incluye mesas normales y BYE).  
- Por cada **partida** (ronda) y jugador se toma **una fila** (en la subconsulta se usa `GROUP BY id_usuario, id_torneo, partida` y `MAX(...)` para cada campo).  
  - **Ganado:** `CASE WHEN resultado1 > resultado2 THEN 1 ELSE 0 END` (partida ganada, incluye BYE).  
  - **Perdido:** `CASE WHEN resultado1 < resultado2 THEN 1 ELSE 0 END`.  
  - **Puntos:** `resultado1` (puntos de esa partida).  
  - **Efectividad, sancion, chancleta, zapato, tarjeta:** el valor de esa columna en `partiresul`.  
- Luego se **suman** esos valores por `(id_usuario, id_torneo)` (todas las partidas del jugador en el torneo).

**Paso 4 – Escribir totales en `inscritos`**  
- Se hace un **único** `UPDATE inscritos ... INNER JOIN (subconsulta agregada) ... SET ganados = ..., perdidos = ..., efectividad = ..., puntos = ..., sancion = ..., chancletas = ..., zapatos = ..., tarjeta = ... WHERE torneo_id = ?`.  
- Solo se actualizan los inscritos que tienen al menos una fila en `partiresul` con `registrado = 1`.

**Paso 5 – Poner a 0 solo quienes no tienen partidas**  
- `UPDATE inscritos ... LEFT JOIN (SELECT DISTINCT id_usuario, id_torneo FROM partiresul WHERE id_torneo = ? AND registrado = 1) has_data ... SET ganados = 0, ... WHERE torneo_id = ? AND has_data.id_usuario IS NULL`.  
- Así solo los inscritos del torneo que **no** tienen ninguna partida registrada pasan a tener estadísticas en 0; el resto ya fue actualizado en el paso 4.

**Paso 6 – Clasificación (posiciones y equipos)**  
- Se llama a **`recalcularClasificacionEquiposYJugadores($torneo_id)`** (paso 6), que hace:
  1. **`recalcularPosiciones($torneo_id)`** — Ordena inscritos por `ganados DESC, efectividad DESC, puntos DESC` (solo confirmados) y asigna **posición** 1, 2, 3, … y **ptosrnk** (puntos de ranking según tabla `clasiranking` si existe).
  2. **`actualizarEstadisticasEquipos($torneo_id)`** — Solo si el torneo es **modalidad equipos**: suma puntos, ganados, perdidos, efectividad, sanciones por `codigo_equipo` desde `inscritos` y actualiza la tabla **`equipos`**; luego recalcula la posición de cada equipo.
  3. **`asignarNumeroSecuencialPorEquipo($torneo_id)`** — Numera 1..4 dentro de cada equipo según la clasificación individual.

### Resumen del flujo de datos

```
partiresul (por partida/jugador, registrado=1)
    → agregar por (id_usuario, id_torneo)
    → inscritos.ganados, perdidos, efectividad, puntos, sancion, chancletas, zapatos, tarjeta
    → recalcularPosiciones → inscritos.posicion, inscritos.ptosrnk
    → [si equipos] actualizarEstadisticasEquipos → equipos.* y posiciones de equipos
```

---

## Paso 5: Generación de la siguiente ronda

- **Flujo en `generarRonda()`:**
  1. Comprueba permisos y que haya al menos 4 inscritos confirmados.
  2. Comprueba que la **última ronda** tenga todas las mesas con resultados (`todasLasMesasCompletas()`).
  3. **Invoca `actualizarEstadisticasInscritos($torneo_id)`** antes de asignar la siguiente ronda, para evitar omitir la actualización de resultados.
  4. Calcula `proxima_ronda = ultima_ronda + 1`.
  5. **Si `proxima_ronda === 3`:** ejecuta `marcarNoPresentesRetiradosAntesRonda3($torneo_id)` (marca como retirados a pendientes sin partidas) y revalida que sigan ≥ 4 confirmados.
  6. Llama al servicio (`generarAsignacionRonda()` según modalidad).

**Conclusión:** La actualización de estadísticas se ejecuta siempre antes de la asignación de la siguiente ronda; **cumple** con lo establecido.

---

## Evaluación de rendimiento y mejoras posibles

### 1. Inscripciones (Paso 1 y 2)

- **Rendimiento:** Inserción/actualización puntual en `inscritos`; bajo impacto.
- **Mejora:** Asegurar índice en `inscritos (torneo_id, id_usuario)` y en `(torneo_id, estatus)` para listados y conteos.

### 2. Generación de rondas (Paso 3)

- **Rendimiento:** `obtenerClasificacionInscritos()` hace un SELECT con JOINs; para muchos inscritos puede ser costoso si no hay índices.
- **Mejoras:**
  - Índices: `inscritos (torneo_id, estatus)`, `inscritos (torneo_id, posicion, ganados, efectividad, puntos)` (o al menos torneo_id + campos de orden).
  - En rondas intermedias, `obtenerMatrizCompañerosParaRonda` y bucles por ronda pueden generar varias consultas; considerar cachear en memoria por ronda o una sola consulta agregada por torneo si el número de rondas es alto.

### 3. BYE (`aplicarBye`)

- **Rendimiento:** Un DELETE por ronda, un INSERT por jugador BYE, un UPDATE masivo por ronda, más N llamadas a `InscritosPartiresulHelper::actualizarEstadisticas($idUsuario, $torneoId)` por cada BYE (posible N+1).
- **Mejora:** Reemplazar las N llamadas individuales por una única actualización masiva al final (o ejecutar `actualizarEstadisticasInscritos($torneo_id)` una vez después de aplicar todos los BYE), para evitar N+1.

### 4. Actualización de estadísticas (Paso 4)

- **Rendimiento:** Un UPDATE masivo que pone a cero todos los inscritos del torneo; luego un SELECT con subconsulta agrupada y un UPDATE por fila en bucle.
- **Mejoras:**
  - Sustituir el bucle de UPDATE por un único UPDATE con JOIN (o UPDATE desde tabla temporal) para reducir round-trips a la BD.
  - Índices en `partiresul (id_torneo, registrado)` y `(id_torneo, id_usuario, partida)` para la agregación.

### 5. Generación siguiente ronda (Paso 5)

- **Rendimiento:** Dominado por actualización de estadísticas y por la asignación (consultas de clasificación, mesas, historial).
- **Mejora:** Ya se evita omitir la actualización al invocarla siempre antes de generar; las mejoras de los puntos 3 y 4 benefician este paso.

---

## Mejoras una por una (estado, problema, mejora, impacto, logro)

Cada ítem sigue el formato: **Estado actual** → **Problema** → **Mejora sugerida** → **Impacto en rendimiento y funcionalidad** → **Qué logramos**.

---

### Mejora 1: BYE – Eliminar N+1 en `aplicarBye()` ✅ IMPLEMENTADA

| Aspecto | Descripción |
|--------|-------------|
| **Estado actual** | En `MesaAsignacionService::aplicarBye()` se hacía un `UPDATE` masivo en `partiresul` para la regla BYE y luego un **bucle** que llamaba a `InscritosPartiresulHelper::actualizarEstadisticas($idUsuario, $torneoId)` por cada jugador BYE (hasta 3 por ronda). |
| **Problema** | Patrón N+1: N consultas adicionales (SELECT + UPDATE por usuario) solo para actualizar estadísticas de inscritos. En torneos con muchas rondas y BYE repetidos, suma round-trips innecesarios a la BD. |
| **Mejora sugerida** | No actualizar estadísticas por jugador dentro de `aplicarBye()`. Las estadísticas de `inscritos` se recalculan desde `partiresul` cuando se ejecuta `actualizarEstadisticasInscritos($torneo_id)` (antes de la siguiente ronda o al guardar resultados). |
| **Impacto en rendimiento** | Se eliminan hasta 3 consultas extra por ronda donde haya BYE (y las posibles lecturas/escrituras dentro de `actualizarEstadisticas`). Menos carga en BD y menor tiempo de respuesta al generar una ronda. |
| **Impacto en funcionalidad** | Ninguno: los campos de `partiresul` (resultado1, efectividad, registrado) ya quedan correctos; la agregación hacia `inscritos` se hace en el siguiente paso del flujo (actualización de estadísticas). |
| **Qué logramos** | Código más simple, sin N+1 en BYE, y mismo comportamiento funcional. |

**Implementación:** En `config/MesaAsignacionService.php` se eliminó el `foreach` que llamaba a `InscritosPartiresulHelper::actualizarEstadisticas` y se dejó un comentario indicando que las estadísticas se actualizan al generar la siguiente ronda o al guardar resultados.

---

### Mejora 2: Índices en `inscritos` (listados y conteos) ✅ IMPLEMENTADA

| Aspecto | Descripción |
|--------|-------------|
| **Estado actual** | Existían índices como `idx_torneo`, `idx_estatus`, `idx_usuario`; faltaba compuesto `(torneo_id, estatus)` y uno para la ordenación de clasificación. |
| **Problema** | Consultas que filtran por `torneo_id` y `estatus` (p. ej. contar confirmados, listar inscritos para rondas) podían hacer full scan o usar índices subóptimos. |
| **Mejora sugerida** | Asegurar índices compuestos: `(torneo_id, estatus)` y `(torneo_id, posicion, ganados, efectividad, puntos)` para clasificación. |
| **Impacto en rendimiento** | Menor tiempo en conteos por torneo y en listados de inscritos; mejor uso del índice en `obtenerClasificacionInscritos()`. |
| **Impacto en funcionalidad** | Ninguno; solo optimización. |
| **Qué logramos** | Respuesta más rápida en pantallas de inscripciones, panel del torneo y generación de rondas cuando hay muchos inscritos. |

**Implementación:** Se añadieron en `schema/schema.sql` y en `scripts/add_missing_indices.php`: `idx_inscritos_torneo_estatus (torneo_id, estatus)` y `idx_inscritos_clasificacion (torneo_id, posicion, ganados, efectividad, puntos)`. Opcional: `sql/add_indices_mejoras_2_y_4.sql` para ejecución manual.

**Estado:** Implementada (ejecutar `php scripts/add_missing_indices.php` en instalaciones existentes).

---

### Mejora 3: Actualización de estadísticas – Un solo UPDATE con JOIN ✅ IMPLEMENTADA

| Aspecto | Descripción |
|--------|-------------|
| **Estado actual** | `actualizarEstadisticasInscritos()` ponía a cero todas las filas de `inscritos` del torneo, ejecutaba un SELECT con subconsulta agrupada por (id_usuario, id_torneo) y luego un **bucle** con un UPDATE por cada fila devuelta. |
| **Problema** | Si hay muchos inscritos, se ejecutaban N UPDATEs individuales (uno por jugador), lo que implicaba N round-trips a la BD y mayor tiempo y carga. |
| **Mejora sugerida** | Sustituir el bucle por un único `UPDATE inscritos i INNER JOIN ( ... subconsulta agregada ... ) agg ON i.id_usuario = agg.id_usuario AND i.torneo_id = agg.id_torneo SET i.ganados = agg.ganados, ...`. |
| **Impacto en rendimiento** | De N UPDATEs a 1 UPDATE (la agregación va dentro del JOIN). Menor tiempo y menor carga en la BD en torneos con muchos participantes. |
| **Impacto en funcionalidad** | Ninguno: la misma lógica de sumas por usuario/torneo desde partiresul se mantiene en la subconsulta. |
| **Qué logramos** | Actualización de estadísticas más rápida y escalable. |

**Implementación:** En `actualizarEstadisticasInscritos()` se reemplazó el SELECT + bucle de UPDATEs por un único `UPDATE inscritos i INNER JOIN ( subconsulta agregada ) agg ON ... SET i.ganados = agg.ganados, ... WHERE i.torneo_id = ?`. La subconsulta interna es la misma (por_ronda por partida, luego suma por id_usuario, id_torneo).

**Estado:** Implementada.

---

### Mejora 4: Índices en `partiresul` (agregación) ✅ IMPLEMENTADA

| Aspecto | Descripción |
|--------|-------------|
| **Estado actual** | La agregación en `actualizarEstadisticasInscritos()` recorre `partiresul` con `WHERE id_torneo = ? AND registrado = 1` y agrupa por `id_usuario`, `id_torneo`, `partida`. |
| **Problema** | Sin índices adecuados, la agregación podía ser costosa en torneos con muchas partidas y jugadores. |
| **Mejora sugerida** | Índices compuestos: `(id_torneo, registrado)` y `(id_torneo, id_usuario, partida)` para la consulta de agregación y la eliminación de duplicados. |
| **Impacto en rendimiento** | Menor tiempo en la fase de agregación y en la limpieza de duplicados. |
| **Impacto en funcionalidad** | Ninguno. |
| **Qué logramos** | Mejor tiempo de respuesta al actualizar estadísticas y al generar la siguiente ronda. |

**Implementación:** Se añadieron en `sql/migrate_partiresul_table.sql` (CREATE TABLE) y en `scripts/add_missing_indices.php`: `idx_partiresul_torneo_registrado (id_torneo, registrado)` y `idx_partiresul_torneo_usuario_partida (id_torneo, id_usuario, partida)`. Opcional: `sql/add_indices_mejoras_2_y_4.sql` para ejecución manual.

**Estado:** Implementada (ejecutar `php scripts/add_missing_indices.php` en instalaciones existentes; nuevas instalaciones con migrate_partiresul_table.sql ya incluyen los índices).

---

## Resumen de cumplimiento

| Requisito | Estado |
|-----------|--------|
| Inscripciones en línea → inscritos estatus pendiente | Cumple |
| Inscripciones en sitio → inscritos (confirmado en guardado) | Cumple |
| Verificación en sitio (confirmar participación) | Cumple (módulo Inscripciones) |
| No presentes eliminados al generar 3.ª ronda | Cumple (marcados como retirados) |
| Primera ronda: mesas de 4, BYE con ganados/efectividad/resultado1 | Cumple |
| Actualización estadísticas desde partiresul → inscritos | Cumple |
| Actualización antes de generar siguiente ronda | Cumple |
| BYE: partida ganada, 50% efectividad, 100% puntos en partiresul | Cumple |

---

---

## Reporte de posiciones (action=posiciones)

- **URL:** `index.php?page=torneo_gestion&action=posiciones&torneo_id=X`
- **Procedencia de los datos:** La tabla mostrada lee de **`inscritos`** (posición, ganados, perdidos, efectividad, puntos, ptosrnk, sancion, tarjeta) y de **subconsultas a `partiresul`** (ganadas por forfait, partidas BYE).
- **Sincronización:** Al cargar el reporte se llama a **`actualizarEstadisticasInscritos($torneo_id)`** para que los totales en `inscritos` coincidan con `partiresul` y el listado refleje la realidad. Si esa llamada falla, se registra el error y se muestra lo que haya en `inscritos` sin bloquear la página.

---

*Documento generado a partir del análisis del código en `config/MesaAsignacionService.php`, `modules/torneo_gestion.php`, `lib/InscritosHelper.php`, y módulos de inscripciones/inscribir_sitio/revisar_inscripciones.*
