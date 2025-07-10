## How to run

1. `cp .env.example .env`
2. Configure .env
3. `docker compose up -d`
4. `docker compose exec backend composer install`
5. `docker compose exec backend npm install`
6. `docker compose exec backend php artisan key:generate`
7. `docker compose exec backend php artisan migrate`
8. `sudo chmod 777 -R storage/`

## How to generate docs
`php artisan scribe:generate`
