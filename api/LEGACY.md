# Laravel — proyecto histórico (motor en pausa)

Desarrollo del motor de trading trasladado a `../python-bot/`. Nada de este proyecto se ha borrado ni alterado (BD, migraciones, experimentos incluidos).

## Cómo está aislado
El bot de Laravel solo opera si alguien mantiene en marcha `php artisan schedule:work` (o `composer dev` / `php artisan dev`), que ejecuta cada minuto `automatic:process`, `automatic:search` y `cycles:expire-hold` (ver `routes/console.php`). No hay tarea programada de Windows ni proceso PHP activo; sin iniciar esos comandos, el bot no se ejecuta.

## Reactivar
```powershell
cd api
composer install      # si falta vendor/
php artisan migrate:status   # comprobar BD (MySQL debe estar levantado)
php artisan schedule:work    # reanuda el bot (o: composer dev)
```
Detener: Ctrl+C. Revisa antes `.env` y `config/trading.php` (hay cambios locales sin commit en ese archivo).
