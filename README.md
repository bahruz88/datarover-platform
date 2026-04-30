# DataRover

Self-hosted data governance platform — frontend + PHP backend, Python scanner, schedule service, MySQL.

## Quick start (Docker)

You only need **Docker Desktop** (https://www.docker.com/products/docker-desktop/).

```bash
git clone <this-repo>
cd <this-repo>
copy .env.example .env       # Linux/macOS: cp .env.example .env
docker compose up -d --build
```

Open http://localhost:8080/datarover/

| Service | URL | Container | Notes |
|---|---|---|---|
| App (Apache + PHP 8.2) | http://localhost:8080/datarover/ | `datarover-apache` | |
| Scanner (FastAPI) | http://localhost:8000 | `datarover-scanner` | DB metadata extraction (15+ DBs) |
| Schedule (Flask) | http://localhost:8001 | `datarover-schedule` | Cron-based DQ rule runner |
| MySQL (MariaDB 10.11) | localhost:3307 | `datarover-mysql` | root password from `.env` |
| phpMyAdmin | http://localhost:8082 | `datarover-phpmyadmin` | |

## First-time database setup

`docker/db-init/*.sql` is loaded automatically when the MySQL volume is empty.
The repo does **not** ship a database dump (excluded via `.gitignore`) — supply your own:

```bash
# Place a dump at:
docker/db-init/01-datarover.sql
```

Then `docker compose up -d` will import it on the first MySQL start. To reset and reimport later:

```bash
docker compose down -v   # drops the MySQL volume — destructive
docker compose up -d --build
```

## Configuration

`.env` (next to `docker-compose.yml`) controls ports and the MySQL root password. Defaults are in `.env.example`.

`backend.php` reads DB host / scanner URL from environment variables — set automatically by `docker-compose.yml`. The same code still runs under XAMPP because env vars fall back to `localhost`.

## Project layout

```
.
├── datarover/              # Apache + PHP frontend & backend.php
├── datarover-scanner/      # FastAPI metadata scanner (Oracle, MySQL, Postgres, iomete, ...)
├── dq-schedule-service/    # Flask + apscheduler — schedules DQ rule runs
├── datarover-soda/         # Soda CLI checks the PHP backend invokes
├── docker/
│   ├── db-init/            # SQL files loaded on first MySQL start (gitignored)
│   ├── apache-conf/        # Apache configs (if customised)
│   └── README.md           # Detailed Docker notes
├── docker-compose.yml
└── .env.example
```

## Useful commands

```bash
docker compose ps                                  # status
docker compose logs -f apache                      # tail app logs
docker compose exec mysql mariadb -uroot -prootpw  # SQL shell
docker compose restart apache                      # restart one service
docker compose down                                # stop, keep data
docker compose down -v                             # stop + drop volumes
```
