# Contributing to Tribes Backend

Thank you for your interest in contributing! Please follow these guidelines.

## Getting Started

- **Stack**: Laravel 12, PHP 8.3, Docker
- **Setup**: Clone the repo, copy `.env.example` to `.env`, then run:

```bash
docker compose up -d
```

This starts all required services (PHP, MySQL, Redis, etc.) via Docker.

## Branch Naming

Use the following conventions when creating branches:

- `feature/issue-{id}` — for new features (e.g. `feature/issue-42`)
- `fix/issue-{id}` — for bug fixes (e.g. `fix/issue-99`)

Always branch off from `dev`.

## Pull Requests

- Target the `dev` branch for all PRs.
- Include a short description of what the PR does and why.
- Reference the relevant issue number (e.g. `Closes #337`).
- Ensure all tests pass before requesting review.

## Code Style

- Follow the [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard.
- Run Laravel Pint before committing:

```bash
./vendor/bin/pint
```

Fix any issues reported before pushing your branch.
