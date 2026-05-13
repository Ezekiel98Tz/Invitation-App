# Invitation & RSVP App

An event invitation platform where you can create events, import guests, send invitations, and collect RSVPs via a guest-facing link.

## Features

- Create and manage events
- Import guests from CSV and map columns (name/email/phone)
- Send invitations and reminders (queued jobs)
- Guest RSVP flow at `/invite/{token}`
- Delivery + activity logging

## Tech Stack

- Laravel 11
- MySQL / MariaDB
- Tailwind CSS + Alpine.js
- Vite (dev server + asset build)

## Requirements

- PHP 8.2+
- Composer 2.x
- Node.js + npm
- MySQL / MariaDB

## Local Setup

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Install frontend dependencies:

   ```bash
   npm install
   ```

3. Create `.env`:

   ```bash
   copy .env.example .env
   php artisan key:generate
   ```

4. Configure database in `.env` (example):

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=invitation_app_local
   DB_USERNAME=root
   DB_PASSWORD=
   ```

5. Run migrations + seed:

   ```bash
   php artisan migrate --seed
   ```

6. Run the app:

   ```bash
   php artisan serve
   ```

   Open: http://127.0.0.1:8000

7. Run Vite (in another terminal):

   ```bash
   npm run dev
   ```

   If port 5173 is already used (e.g. another React app), use:

   ```bash
   npm run dev -- --port 5174
   ```

## Default Seed User

The default database seeder creates a test user:

- Email: `test@example.com`
- Password: check the user factory / your seeder configuration (or create your own user).

## Notes

- This project uses database-backed sessions/queue/cache, so ensure migrations are run.
