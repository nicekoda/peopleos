@echo off
REM Runs artisan with this project's scoped PHP config (php.ini in project root),
REM so extensions like pdo_pgsql/pgsql apply only to this project.
set PHPRC=%~dp0php.ini
php "%~dp0artisan" %*
