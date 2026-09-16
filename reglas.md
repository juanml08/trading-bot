# Reglas del Proyecto (Trading Bot)

Reglas obligatorias para cualquier agente que trabaje en este proyecto. Deben respetarse en todo momento.

## Antes de tocar código

- No modificar código sin entender primero el contexto (qué hace, quién lo usa, por qué existe así).
- Si hay duda de arquitectura o de negocio, detenerse y preguntar antes de tomar una decisión importante. No asumir.
- Antes de implementar un cambio importante, explicar brevemente qué se va a modificar y por qué.
- No alterar funcionalidades existentes sin justificar el motivo.

## Arquitectura y estilo

- Mantener la arquitectura simple y escalable. Evitar sobreingeniería.
- Respetar las convenciones de Laravel (estructura, naming, Eloquent, service providers, etc.).
- No crear archivos, clases, capas o abstracciones innecesarias. Si una solución simple funciona, no complicarla.
- Mantener separadas las responsabilidades: estrategia, riesgo, ejecución y persistencia son capas distintas y no deben mezclarse.

## Forma de trabajar

- Hacer cambios pequeños y enfocados. Evitar PRs o commits gigantes que mezclen varias cosas.
- Priorizar seguridad, manejo de errores y trazabilidad (logs, auditoría) en todo el código relacionado con trading.

## Trading y riesgo

- No asumir que una estrategia es rentable. Toda estrategia debe validarse mediante backtesting o paper trading antes de considerarse válida.
- No implementar ni habilitar operaciones con dinero real mientras el proyecto no haya sido validado previamente.
