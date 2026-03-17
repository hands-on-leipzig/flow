# frontend

This template should help get you started developing with Vue 3 in Vite.

## Recommended IDE Setup

[VSCode](https://code.visualstudio.com/) + [Volar](https://marketplace.visualstudio.com/items?itemName=Vue.volar) (and disable Vetur).

## Customize configuration

See [Vite Configuration Reference](https://vite.dev/config/).

## Project Setup

```sh
npm install
```

### Compile and Hot-Reload for Development

**Laravel must be running first** — Vite only serves the Vue app; `/api/*`, `/flow/*`, etc. are proxied to the backend (default **port 8000**).

Terminal 1 (backend):

```sh
cd ../backend && php artisan serve
```

Terminal 2 (frontend):

```sh
npm run dev
```

Optional: `frontend/.env.local` — if the API runs elsewhere, set:

```env
VITE_FILES_BASE_URL=http://127.0.0.1:8000
```

(Must match the URL you use for `artisan serve`.)

#### Vite proxy errors (`ECONNREFUSED` / `ECONNRESET`)

| Message | Meaning |
|--------|---------|
| **connect ECONNREFUSED 127.0.0.1:8000** | Backend not running or wrong port — start `php artisan serve` (or fix `VITE_FILES_BASE_URL`). |
| **read ECONNRESET** | Backend dropped the connection (restart, crash, or stop/start). Wait until Laravel is up, then refresh the app. |

### Compile and Minify for Production

```sh
npm run build
```
