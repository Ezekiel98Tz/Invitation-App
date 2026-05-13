# Progress Log

## 2026-05-13

- Switched local database config to MySQL/MariaDB and ran migrations + seeders
- Fixed asset linking to use Laravel `asset()` helper for built assets
- Installed Node dependencies so `npm run dev` can find `vite`
- Removed `composer.lock` to avoid locking incompatible dependency constraints on PHP 8.2 setups
