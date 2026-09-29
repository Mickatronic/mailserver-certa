#!/bin/sh
set -eu

php /var/www/html/scripts/create_superadmin.php --ensure
exec "$@"
