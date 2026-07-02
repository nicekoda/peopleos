@echo off
REM Runs composer with this project's scoped PHP config (php.ini in project root),
REM so platform requirement checks (ext-pdo_pgsql, ext-pgsql) resolve correctly.
set PHPRC=%~dp0php.ini
composer %*
