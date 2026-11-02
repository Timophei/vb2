#!/usr/bin/env bash
# Обновление vb2 на сервере из GitHub:  sudo bash /var/www/vb2/deploy/update.sh
set -euo pipefail
DIR="${1:-/var/www/vb2}"
cd "$DIR"

echo "→ Забираю изменения из GitHub…"
git fetch --quiet origin
git reset --hard origin/main   # config.php и data/ не трогаются — они в .gitignore

echo "→ Права…"
mkdir -p data
chown -R www-data:www-data data
chmod 770 data
[ -f config.php ] && chown root:www-data config.php && chmod 640 config.php

echo "→ Проверка синтаксиса PHP…"
find lib engine public bin -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "→ Перезапуск PHP-FPM…"
systemctl reload php8.3-fpm

if systemctl is-enabled --quiet vb2-worker 2>/dev/null; then
    echo "→ Перезапуск воркера таймеров…"
    systemctl restart vb2-worker
fi
echo "✓ Готово: $(git log -1 --format='%h %s')"
