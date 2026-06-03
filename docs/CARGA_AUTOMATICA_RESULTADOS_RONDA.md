# Carga automática de resultados por ronda (pruebas)

Script para llenar **cualquier ronda** ya asignada en `partiresul` con resultados aleatorios y disciplina de prueba, usando la **misma lógica** que el formulario de ingreso (`TournamentActionHandler::aplicarResultadosMesaCore`).

## Requisitos

- La ronda debe estar **generada** (filas en `partiresul` con `mesa > 0`, 4 jugadores por mesa).

## Uso

```bash
php scripts/carga_automatica_resultados_ronda.php <id_torneo> <partida>
```

### Ejemplos

```bash
# Simular sin guardar + reporte HTML
php scripts/carga_automatica_resultados_ronda.php --dry-run --seed=42 2 2

# Cargar ronda 2 del torneo 2
php scripts/carga_automatica_resultados_ronda.php 2 2

# Personalizar porcentajes y ruta del reporte
php scripts/carga_automatica_resultados_ronda.php 2 3 --ff-pct=3 --sancion-80=2 --sancion-40=2 --amarilla=5 --report=dist/mi_reporte.html
```

## Qué hace

| Elemento | Comportamiento |
|----------|----------------|
| **Puntos por mesa** | Valores diversos por pareja A vs B (ganador aleatorio, variaciones entre mesas). |
| **Forfait** | ~3% del plantel de la ronda (`--ff-pct`, default 3). |
| **Sanción -80** | ~2% (`--sancion-80`). |
| **Sanción -40** | ~2% (`--sancion-40`). |
| **Amarilla** | ~5% con `tarjeta=1`, `sancion=0`, sin forfait (`--amarilla`). |

Cada jugador recibe **como máximo una** incidencia (forfait tiene prioridad sobre el resto).

## Reporte

- Consola: listado mesa, letra, jugador, tipo de incidencia.
- HTML: `dist/reporte_faltas_torneo{id}_r{partida}_fecha.html` (o `--report=ruta`).

Columnas del reporte: mesa, letra (A/C/B/D), jugador, incidencia, sanción, tarjeta, forfait, R1, R2, efectividad.

## Después de cargar

1. En el panel: **Actualizar estadísticas** (o generar la siguiente ronda, que ya lo hace el flujo).
2. Revisar posiciones / cuadrícula.

## Archivos

- `scripts/carga_automatica_resultados_ronda.php` — CLI
- `lib/CargaAutomaticaResultadosRondaService.php` — lógica

Relacionado (demo menor, 6 filas): `scripts/partiresul_demo_sanciones.php`.
