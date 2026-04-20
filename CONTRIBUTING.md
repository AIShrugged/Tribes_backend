# Contributing

Thank you for contributing to Tribes_backend. Please follow these guidelines.

## Getting Started

This project uses Laravel 12, PHP 8.3, and Docker.

To start the development environment:

```bash
docker compose up -d
```

## Branch Naming

Use the following conventions:

- Feature work: `feature/issue-{id}`
- Bug fixes: `fix/issue-{id}`

Replace `{id}` with the relevant issue number.

## Pull Requests

- Target the `dev` branch for all pull requests.
- Include a short description of your changes.
- Reference the issue number in the PR description (e.g., "Closes #337").

## Code Style

- Follow the [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard.
- Run Pint before committing:

```bash
./vendor/bin/pint
```