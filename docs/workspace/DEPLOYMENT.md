# Deployment Guide

Deploying a **Swoole** application like Semitexa requires a different approach than traditional PHP-FPM setups.

## 📦 Production Checklist

1.  **Environment Variables**:
    - Set `APP_ENV=prod`.
    - Set `APP_DEBUG=false`.
    - Configure real database & Redis credentials.

2.  **Optimize Autoloader**:
    ```bash
    composer install --no-dev --optimize-autoloader
    ```

3.  **Cache Configuration**:
    - Ensure `var/cache` is writable by the user running the process.
    - Clear stale caches before starting: `php vendor/bin/semitexa cache:clear` inside the container or on the server.

## 🐳 Docker Deployment

We recommend shipping your application as a Docker container.

### `Dockerfile` Optimization
Ensure your `Dockerfile` installs only necessary production extensions and cleans up build dependencies.

Example snippet:
```dockerfile
FROM php:8.4-cli-alpine
# ... install extensions (swoole, pdo_mysql, ...) and Composer ...
COPY . /var/www/html
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader
EXPOSE 9502
CMD ["php", "server.php"]
```

Inside a container the process is `php server.php`, the Swoole server itself; the project's own `Dockerfile` ends the same way. Do **not** use `bin/semitexa server:start` as the container command: it is the host-side wrapper that runs `docker compose`, and there is no Docker inside your production image.

## ⚙️ Process Management (Supervisor)

If not using Docker, use **Supervisor** to keep the Swoole server running. It runs the same `php server.php` process directly on the host.

`/etc/supervisor/conf.d/semitexa.conf`:
```ini
[program:semitexa]
directory=/path/to/project
command=php server.php
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
```

## 🌐 Nginx Reverse Proxy

Put Nginx in front of Swoole to handle SSL termination and static files.

```nginx
server {
    listen 80;
    server_name example.com;
    root /path/to/project/public;

    location / {
        try_files $uri @swoole;
    }

    location @swoole {
        proxy_pass http://127.0.0.1:9502;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

## 🚀 Tuning
- **Port**: the server listens on `SWOOLE_PORT` (default `9502`) on `SWOOLE_HOST` (default `0.0.0.0`). Point the proxy at whatever you set.
- **Worker Count**: Adjust `SWOOLE_WORKER_NUM` (default `4`) in `.env` based on CPU cores (usually `CPU * 2` or `CPU * 4`).
- **Worker recycling**: `SWOOLE_MAX_REQUEST` (default `10000`) restarts a worker after that many requests.
- Event handlers enqueued for asynchronous execution are processed by a separate worker process, `php vendor/bin/semitexa queue:work`, not by Swoole task workers; there is no task-worker setting. Run it under Supervisor or as its own container.
