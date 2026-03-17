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
`php artisan scramble:export --path=public/openapi.json`

## Internal docs

- Agent runtime architecture: [docs/agent-runtime-architecture.md](/home/anor/projects/spodial_hr_backend/docs/agent-runtime-architecture.md)
- Async Wanda chat runtime: [docs/async-chat-runtime.md](/home/anor/projects/spodial_hr_backend/docs/async-chat-runtime.md)
- Async Telegram runtime: [docs/async-telegram-runtime.md](/home/anor/projects/spodial_hr_backend/docs/async-telegram-runtime.md)
- MCP server overview: [docs/mcp-server.md](/home/anor/projects/spodial_hr_backend/docs/mcp-server.md)

## Tests

Run the full suite inside Docker:

`docker compose exec -T backend php artisan test`

LLM/OpenRouter requests are mocked in tests by default in [tests/TestCase.php](/home/anor/projects/spodial_hr_backend/tests/TestCase.php).
