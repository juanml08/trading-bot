# Trading Bot — Monorepo

Este repositorio está dividido en dos proyectos independientes:

- `api/` — Backend Laravel (estrategias, market data, riesgo, backtesting, persistencia). Todas las guías de Laravel Boost y las convenciones de PHP viven en [api/AGENTS.md](api/AGENTS.md). Ejecuta los comandos `artisan`/`composer` con `api/` como directorio de trabajo.
- `site/` — Frontend Vue. Todavía no se construye la interfaz de autenticación con Binance; por ahora es solo la estructura base del proyecto.

Antes de trabajar en el backend, lee [api/AGENTS.md](api/AGENTS.md). `reglas.md` en la raíz aplica a todo el repositorio.
