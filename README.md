# Platform Holidays

Internal leave-management application for Platform.

## Structure

- `apps/web` — Vue 3 / Vite frontend
- `apps/api` — Laravel 13 API

## Frontend

```bash
cd ~/Documents/GitHub/platform-holidays
npm run dev
```

Frontend:
http://localhost:5173

## API

```bash
cd ~/Documents/GitHub/platform-holidays/apps/api
php artisan serve
```

API:
http://localhost:8000

Health check:
http://localhost:8000/api/v1/health

## Local database

Configure the database values in:

```
apps/api/.env
```

Then run:

```bash
cd apps/api
php artisan migrate
```
