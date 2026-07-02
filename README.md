# PeopleOS

Enterprise Human Resource Intelligence Platform. Built with Laravel + PostgreSQL.

## Local Development Setup (Windows / Laragon)

**Requirements**

- PHP 8.3+
- PostgreSQL (this project connects to `peopleos_dev`)
- Composer

**PostgreSQL PHP extensions are scoped to this project only.**

This project requires the `pdo_pgsql` and `pgsql` PHP extensions, but they are
**not** enabled in the machine-wide `php.ini`. Instead, a project-local
`php.ini` lives at the repo root (git-ignored, machine-specific) with those
extensions enabled.

Because Windows PHP CLI does not automatically pick up a `php.ini` from the
current working directory, use the wrapper scripts instead of calling `php`,
`artisan`, or `composer` directly from this project:

```bash
./artisan.bat migrate
./artisan.bat test
./composer.bat install
```

These wrappers set `PHPRC` to the project's `php.ini` before invoking the
underlying command, so the extensions apply only within this project and
never leak into other projects on the same machine.

If `php.ini` doesn't exist yet (fresh clone), copy it from your machine's
base Laragon `php.ini` and uncomment the `pdo_pgsql` and `pgsql` extension
lines.

**Database configuration**

Copy `.env.example` to `.env` and fill in your local PostgreSQL credentials
(`DB_CONNECTION=pgsql`). Then run:

```bash
./artisan.bat migrate
```

## Project Standards

See `PeopleOS Master Development Constitution` and related standards
documents (security, database, API, QA, Git, AI governance) for the rules
governing how this codebase is built. Development proceeds checkpoint by
checkpoint — no major feature is added without explicit scope agreement.
