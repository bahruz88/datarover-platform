# DataRover — Docker Setup

Single-command stack for the whole DataRover platform:

| Service | Container | Host port | Notes |
|---|---|---|---|
| Apache + PHP 8.2 (`datarover` site) | `datarover-apache` | `8080` | http://localhost:8080/datarover/ |
| Scanner (FastAPI) | `datarover-scanner` | `8000` | http://localhost:8000 |
| Schedule (Flask) | `datarover-schedule` | `8001` | http://localhost:8001 |
| MariaDB 10.11 | `datarover-mysql` | `3307` | root password: `rootpw` (see `.env`) |
| phpMyAdmin | `datarover-phpmyadmin` | `8082` | http://localhost:8082 |

## First run

1. **Stop XAMPP** (Apache + MySQL) so ports/processes don't fight Docker.
2. Make sure Docker Desktop is running.
3. From `c:\xampp\htdocs`:
   ```bash
   copy .env.example .env
   docker compose up -d --build
   ```
4. First start auto-imports the dump in `docker/db-init/01-datarover.sql` into the `datarover` database (your real data).
5. Open http://localhost:8080/datarover/

## Re-dumping XAMPP data

The dump is loaded **only on the first start** (when the MySQL volume is empty).
To pick up newer XAMPP data:

```bash
# 1) refresh the dump from a running XAMPP MySQL
docker\migrate-db.bat

# 2) wipe Docker MySQL volume + restart  (DESTROYS Docker DB only — XAMPP untouched)
docker compose down -v
docker compose up -d --build
```

## Useful commands

```bash
docker compose ps                    # status
docker compose logs -f apache        # tail Apache logs
docker compose logs -f scanner       # tail scanner logs
docker compose exec mysql mariadb -uroot -prootpw datarover    # SQL shell
docker compose exec apache bash      # PHP container shell
docker compose down                  # stop (keep data)
docker compose down -v               # stop + drop MySQL/schedule volumes
```

## Configuration

Edit `.env` (next to `docker-compose.yml`) to change ports or the MySQL root password.
The Apache container reads DB and scanner settings from env vars, so backend.php works
both inside Docker and under XAMPP — XAMPP defaults remain `localhost` / empty password.

## What's mounted

- `./datarover` → `/var/www/html/datarover` (live edits — no rebuild needed for PHP/JS changes)
- `./datarover-soda` → `/var/www/html/datarover-soda` (Soda scripts the PHP backend invokes)

The scanner and schedule-service are baked into the image (`COPY .`).
Rebuild them after Python changes:
```bash
docker compose build scanner
docker compose up -d scanner
```
