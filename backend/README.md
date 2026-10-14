# CreatePosts — backend

PHP + MySQL JSON API for the CreatePosts app (the React frontend is in `../frontend`).

- `api/`: the JSON API front controller (`api/index.php`) and its routes
- `lib/`: application code, blocked from direct web access
- `publish_worker.php`, `schedule_worker.php`: background workers (HTTP kick, optional cron)
- `config.php`: defaults. The database login goes in `config.local.php` (git-ignored), which `npm run build` in the repository root creates from `config.local.example.php`.

See the [README in the repository root](../README.md) for installing and updating.
