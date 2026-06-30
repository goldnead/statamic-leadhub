#!/usr/bin/env bash
set -euo pipefail

ADDON_PACKAGE="goldnead/statamic-leadhub"
ADDON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLAYGROUND_DIR="${ADDON_DIR}/playground"

cd "${ADDON_DIR}"

echo "==> Installing addon Composer dependencies"
composer install --no-interaction --no-progress

# Build the playground (fresh Statamic install + this addon wired in as a path
# repo, CP assets compiled & published, demo form/user/data seeded). The script
# is idempotent: it skips the Statamic scaffold if playground/ already exists,
# but always refreshes the CP assets and demo data.
echo "==> Building Statamic playground (this takes a few minutes on first run)"
bash "${ADDON_DIR}/scripts/setup-playground.sh"

# Only install Playwright browsers if the addon actually uses them.
if [ -d "${ADDON_DIR}/tests/Browser" ] || grep -q '"playwright"' "${ADDON_DIR}/package.json" 2>/dev/null; then
    echo "==> Installing Playwright browsers"
    npx --yes playwright install chromium >/dev/null 2>&1 || true
fi

cat <<BANNER

================================================================
  Codespace ready for ${ADDON_PACKAGE}

  Start the Statamic dev server:
      cd playground && php artisan serve --host=0.0.0.0 --port=8000
      → open http://127.0.0.1:8000/cp  (login: admin@example.com / password)

  Rebuild addon CP assets after editing resources/js or resources/css:
      npm run build
      cd playground && php artisan vendor:publish --tag=statamic-leadhub --force

  Run addon tests:
      composer test

  Source:     src/
  Playground: playground/  (gitignored; per-codespace scaffold)
================================================================
BANNER
