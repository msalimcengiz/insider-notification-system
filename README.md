# Insider Notification System

An event-driven, multi-channel notification system (SMS, Email, Push) built with **Laravel 13**, **PostgreSQL**, **Redis** and **Laravel Horizon**.

> 🚧 Work in progress. Full architecture notes, API examples and design decisions will be documented here.

## Quick start

Requirements: Docker with Docker Compose.

```bash
docker compose up
```

The first boot installs dependencies, creates `.env`, generates the app key and runs migrations automatically.

| Service | URL |
|---|---|
| API | http://localhost:8000 |
| Horizon dashboard | http://localhost:8000/horizon |

## Running the tests

```bash
docker compose exec app php artisan test
```

## Code quality

```bash
docker compose exec app composer lint    # Pint (check) + Larastan level 8
docker compose exec app composer format  # Auto-fix code style
```
