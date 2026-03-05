FROM php:8.2-cli

WORKDIR /app
COPY . .

# Ensure SQLite extensions are available (included by default in php:8.2-cli)
# Verify with: php -m | grep -i sqlite

CMD ["bash", "-c", "cd payroll/backend && php -S 0.0.0.0:${PORT:-8080} router.php"]
