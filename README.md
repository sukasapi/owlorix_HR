# Owlorix HR web app

Laravel 12 + Inertia React. Web pages for employees, Management, and Superadmin, and the `/api/v1` API for the desktop app. Specs and conventions: [`../docs`](../docs/README.md), start with [05-web-conventions.md](../docs/05-web-conventions.md).

## First run on Laragon

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# create MySQL databases owlorix_hr and owlorix_hr_test (utf8mb4)
php artisan migrate --seed   # prints the first Superadmin's temporary password once
npm run build                # or: npm run dev
php artisan serve            # http://127.0.0.1:8000
```

Sign in as `superadmin` with the printed password; the app asks for a new password first.

To serve another database (for example `owlorix_hr_verify`), set `DB_DATABASE` in the shell and add `--no-reload`: without it `artisan serve` restarts PHP with the database from `.env`.

```powershell
$env:DB_DATABASE='owlorix_hr_verify'; php artisan serve --port=8001 --no-reload
```

## Tests

```bash
php artisan test
```

Tests run on MySQL database `owlorix_hr_test` and never touch `owlorix_hr`.
