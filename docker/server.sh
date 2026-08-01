#!/usr/bin/env sh
set -e

managed_pids=""

stop_managed_processes() {
    trap - TERM INT

    for pid in $managed_pids; do
        kill -TERM "$pid" 2>/dev/null || true
    done

    for pid in $managed_pids; do
        wait "$pid" 2>/dev/null || true
    done
}

handle_shutdown() {
    stop_managed_processes
    exit 0
}

trap handle_shutdown TERM INT

php-fpm -F &
managed_pids="$managed_pids $!"

nginx -g 'daemon off;' &
managed_pids="$managed_pids $!"

if [ "${LARASEND_RUN_WORKERS:-false}" = "true" ]; then
    php artisan queue:work --queue=default,webhooks --tries=3 --timeout=90 &
    managed_pids="$managed_pids $!"

    php artisan schedule:work &
    managed_pids="$managed_pids $!"
fi

while true; do
    for pid in $managed_pids; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo "A managed Larasend process exited unexpectedly; stopping the container so the platform can restart it."
            stop_managed_processes
            exit 1
        fi
    done

    sleep 2
done
