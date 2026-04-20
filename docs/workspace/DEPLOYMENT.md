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
    - Run `bin/semitexa cache:clear` before starting.

## 🐳 Docker Deployment

We recommend shipping your application as a Docker container.

### `Dockerfile` Optimization
Ensure your `Dockerfile` installs only necessary production extensions and cleans up build dependencies.

Example snippet:
```dockerfile
FROM php:8.4-cli-alpine
# ... install extensions ...
COPY . /app
WORKDIR /app
RUN composer install --no-dev --optimize-autoloader
CMD ["bin/semitexa", "server:start"]
```

## ⚙️ Process Management (Supervisor)

If not using Docker, use **Supervisor** to keep the Swoole server running.

`/etc/supervisor/conf.d/semitexa.conf`:
```ini
[program:semitexa]
command=/path/to/project/bin/semitexa server:start
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
        proxy_pass http://127.0.0.1:9501;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

## 🚀 Tuning
- **Worker Count**: Adjust `SWOOLE_WORKER_NUM` in `.env` based on CPU cores (usually `CPU * 2` or `CPU * 4`).
- **Task Workers**: If using async tasks, tune `SWOOLE_TASK_WORKER_NUM`.
