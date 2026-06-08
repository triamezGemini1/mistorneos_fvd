# Procedimiento: titulares, banca y sustitución en torneos por equipos

## Concepto

| Rol | Campo `inscritos.activo_mesa` | Mesas | Estadísticas / codigo_equipo |
|-----|------------------------------|-------|------------------------------|
| **Titular** | `1` | Sí entra a asignación | Sí |
| **Banca (suplente)** | `0` | No entra a mesas | Sí (mismo código de equipo) |

Cada equipo debe tener **exactamente 4 titulares** (modalidad equipos). Puede tener **N suplentes** adicionales en banca.

## Importación Access

En **parejas inscritas**, columna opcional:

- `activo_mesa`, `activo`, `titular`, `banca`, `estatus_mesa`
- Valores titular: `1`, `si`, `titular`, `activo`
- Valores banca: `0`, `no`, `banca`, `inactivo`, `suplente`

Si **no hay columna**, los primeros 4 jugadores de cada código de equipo en el archivo quedan titulares; el resto en banca.

La verificación de integridad exige **4 titulares**, no limita el tamaño total de la plantilla.

## Sustitución durante el torneo

1. Ir a **Gestión de torneos → Gestionar inscripciones (equipos)**.
2. En equipos con suplentes, botón **Sustituir**.
3. Elegir **titular que sale** y **suplente que entra**.
4. Efecto:
   - El titular pasa a `activo_mesa = 0` (banca), **conserva** `codigo_equipo` y resultados en `partiresul`.
   - El suplente pasa a `activo_mesa = 1` y **entra a mesas** desde la siguiente ronda generada.
5. Queda registro en `equipo_sustituciones`.

## Migración de base de datos

Ejecutar una vez:

```bash
php sql/run_add_activo_mesa_inscritos.php
```

Crea `inscritos.activo_mesa` y tabla `equipo_sustituciones`.

## Motor de mesas

`MesaAsignacionEquiposService` solo considera inscritos con `activo_mesa = 1` (o NULL legacy = titular).
