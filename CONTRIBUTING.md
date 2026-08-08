# Contributing to Tribes Backend

Thank you for contributing to this project. Please follow the guidelines below.

## Getting Started

This project uses Laravel 12 with PHP 8.3 and Docker.

1. Clone the repository and copy the environment file:
   ```bash
   cp .env.example .env
   ```
2. Start the application with Docker:
   ```bash
   docker compose up -d
   ```
3. Install PHP dependencies:
   ```bash
   docker compose exec app composer install
   ```
4. Generate the application key:
   ```bash
   docker compose exec app php artisan key:generate
   ```
5. Run migrations:
   ```bash
   docker compose exec app php artisan migrate
   ```

## Branch Naming

Use the following conventions when naming branches:

- New features: `feature/issue-{id}` (e.g. `feature/issue-42`)
- Bug fixes: `fix/issue-{id}` (e.g. `fix/issue-99`)

Always branch off from `dev`.

## Pull Requests

- Target the `dev` branch for all pull requests.
- Include a short description of the changes in the PR body.
- Reference the related issue number (e.g. `Closes #337`).
- Ensure all tests pass before requesting review.
- Keep PRs focused — one feature or fix per PR.

## Code Style

This project follows [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.

Before committing, run Laravel Pint to auto-fix style issues:

```bash
./vendor/bin/pint
```

Commits that fail the style check will not be merged.
