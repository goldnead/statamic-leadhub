#!/usr/bin/env bash
#
# Build a local, runnable Statamic test environment ("playground") for
# goldnead/statamic-leadhub.
#
# Unlike scripts/smoke-test.sh (which spins up a throwaway project in /tmp,
# runs assertions, then leaves it behind), this script builds a PERSISTENT
# playground at ./playground that you can develop against and click through:
#
#   * fresh Statamic v6 install (pinned to Laravel 12, which both Statamic 6
#     and this addon support — the default skeleton ships Laravel 13, which
#     the addon does not yet declare support for)
#   * this LeadHub source tree wired in as a Composer *path* repository
#     (edit src/ → changes are live in the playground, no reinstall)
#   * SQLite database, migrations run, config published
#   * the addon's Inertia/Vue CP assets compiled and published
#   * a Control Panel super-user created non-interactively
#   * a demo "contact" form + LeadHub mapping + a few sample leads
#
# After it finishes:
#
#   cd playground && php artisan serve --host=0.0.0.0 --port=8000
#   → open http://127.0.0.1:8000/cp  (login printed at the end)
#
# Re-running is safe: the scaffold is skipped if playground/ exists, but the
# CP assets, demo form, user and sample data are (re)built every run. Pass
# --fresh to wipe and rebuild the playground from scratch.
#
# Env overrides:
#   LARAVEL_CONSTRAINT  default ^12.40  (Statamic 6 supports ^12.40 || ^13)
#   CP_EMAIL            default admin@example.com
#   CP_PASSWORD         default password
#   PHP_BIN             default php
#   COMPOSER_BIN        default composer

set -euo pipefail
IFS=$'\n\t'

# ------------------------------------------------------------------
# Config
# ------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLAYGROUND_DIR="$ADDON_DIR/playground"

LARAVEL_CONSTRAINT="${LARAVEL_CONSTRAINT:-^12.40}"
CP_EMAIL="${CP_EMAIL:-admin@example.com}"
CP_PASSWORD="${CP_PASSWORD:-password}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# Running as root in CI/containers needs this for Composer.
export COMPOSER_ALLOW_SUPERUSER=1

FRESH=0
[ "${1:-}" = "--fresh" ] && FRESH=1

if [ -t 1 ]; then
    GREEN='\033[0;32m'; BLUE='\033[0;34m'; YELLOW='\033[0;33m'; BOLD='\033[1m'; NC='\033[0m'
else
    GREEN=''; BLUE=''; YELLOW=''; BOLD=''; NC=''
fi
step() { echo; echo -e "${BLUE}${BOLD}▸${NC} ${BOLD}$*${NC}"; }
ok()   { echo -e "  ${GREEN}✓${NC} $*"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $*"; }

# A throwaway PHP bootstrap helper: runs a snippet with the playground app
# booted. Usage: php_app '<php code>'
php_app() { ( cd "$PLAYGROUND_DIR" && "$PHP_BIN" -r '
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
'"$1" ); }

# ------------------------------------------------------------------
# Pre-flight
# ------------------------------------------------------------------
step "Pre-flight"
command -v "$PHP_BIN" >/dev/null      || { echo "php not found"; exit 1; }
command -v "$COMPOSER_BIN" >/dev/null || { echo "composer not found"; exit 1; }
command -v npm >/dev/null             || { echo "npm not found"; exit 1; }
"$PHP_BIN" -m | grep -qi sqlite       || { echo "php sqlite extension required"; exit 1; }
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;'), Composer $("$COMPOSER_BIN" --version | awk '{print $3}'), Node $(node -v)"
ok "Addon source: $ADDON_DIR"

if [ "$FRESH" = "1" ] && [ -d "$PLAYGROUND_DIR" ]; then
    step "Removing existing playground (--fresh)"
    rm -rf "$PLAYGROUND_DIR"
    ok "playground/ removed"
fi

# ------------------------------------------------------------------
# 1. Scaffold Statamic (skip if already present)
# ------------------------------------------------------------------
if [ -d "$PLAYGROUND_DIR" ]; then
    warn "playground/ already exists — skipping scaffold. Pass --fresh to rebuild."
else
    step "Creating Statamic project at playground/ (Laravel pinned to $LARAVEL_CONSTRAINT)"
    "$COMPOSER_BIN" create-project --prefer-dist --no-interaction --no-scripts \
        statamic/statamic "$PLAYGROUND_DIR" 2>&1 | tail -4
    cd "$PLAYGROUND_DIR"

    "$COMPOSER_BIN" install --no-interaction --prefer-dist 2>&1 | tail -3
    ok "Statamic installed"

    # --- SQLite + APP_KEY ---
    step "Configuring SQLite + APP_KEY"
    [ -f .env ] || { cp .env.example .env 2>/dev/null || touch .env; }
    "$PHP_BIN" -r '
    $p=".env"; $e=file_exists($p)?file_get_contents($p):"";
    $lines=array_filter(preg_split("/\r?\n/",$e),fn($l)=>!preg_match("/^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=/",$l));
    $lines[]="DB_CONNECTION=sqlite";
    $lines[]="DB_DATABASE=".__DIR__."/database/database.sqlite";
    file_put_contents($p,implode("\n",$lines)."\n");'
    mkdir -p database && touch database/database.sqlite
    "$PHP_BIN" artisan key:generate --force --no-interaction >/dev/null
    "$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -2
    ok "SQLite configured, base tables migrated"

    # --- Wire LeadHub as a path repo ---
    step "Wiring LeadHub as a Composer path repository"
    "$COMPOSER_BIN" config repositories.leadhub path "$ADDON_DIR" --no-interaction
    "$COMPOSER_BIN" config minimum-stability dev --no-interaction
    "$COMPOSER_BIN" config prefer-stable true --no-interaction
    "$COMPOSER_BIN" config --no-interaction allow-plugins.pixelfear/composer-dist-plugin true
    "$COMPOSER_BIN" config --no-interaction allow-plugins.composer/installers true
    "$COMPOSER_BIN" config --no-interaction allow-plugins.php-http/discovery true

    # The Statamic 6 skeleton requires laravel/framework ^13, but the addon
    # declares ^11|^12. Statamic 6 itself supports ^12.40 || ^13, so we pin the
    # host to Laravel 12 (the highest the addon allows). Editing composer.json
    # then requiring with -W lets Composer regenerate the lock and downgrade
    # laravel/framework in a single resolution pass.
    LARAVEL_CONSTRAINT="$LARAVEL_CONSTRAINT" "$PHP_BIN" -r '$f="composer.json";$j=json_decode(file_get_contents($f),true);$j["require"]["laravel/framework"]=getenv("LARAVEL_CONSTRAINT");file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");'
    "$COMPOSER_BIN" require "goldnead/statamic-leadhub:@dev" -W --no-interaction --prefer-dist 2>&1 | tail -4
    ok "LeadHub installed on Laravel $("$COMPOSER_BIN" show laravel/framework 2>/dev/null | awk '/^versions/{print $3}') (path repo — src/ edits are live)"

    "$PHP_BIN" artisan vendor:publish --tag=leadhub-config --force --no-interaction >/dev/null || true
    "$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -2
    ok "LeadHub config published + tables migrated"
fi

cd "$PLAYGROUND_DIR"

# ------------------------------------------------------------------
# 2. Compile + publish the addon's Control Panel assets
# ------------------------------------------------------------------
# The addon ships its own Vite build (Inertia + Vue 3 + Tailwind). It is built
# from the ADDON directory (its vite.config.js externalises @statamic/cms, so
# @statamic/cms need not be installed). --legacy-peer-deps avoids npm trying to
# fetch the @statamic/cms peer (a PHP package, not on the npm registry).
step "Publishing addon CP assets"
# The addon ships its compiled CP assets under resources/dist/build/ (committed).
# We just publish them into the playground. If they're missing (e.g. a fresh
# checkout before a build), compile them the Statamic-6 way first — this needs
# the addon's own Composer deps (for the @statamic/cms file dependency).
if [ ! -f "$ADDON_DIR/resources/dist/build/manifest.json" ]; then
    warn "resources/dist/build/manifest.json missing — building from source"
    ( cd "$ADDON_DIR"
      [ -d vendor/statamic/cms ] || "$COMPOSER_BIN" install --no-interaction --prefer-dist 2>&1 | tail -2
      npm install --no-audit --no-fund 2>&1 | tail -2
      npm run build 2>&1 | tail -6 )
fi
if [ -f "$ADDON_DIR/resources/dist/build/manifest.json" ]; then
    ok "Addon assets present → resources/dist/build/manifest.json"
else
    warn "resources/dist/build/manifest.json still missing — CP may 500 on asset load"
fi
# Publish into the playground so Statamic serves them from public/vendor/...
"$PHP_BIN" artisan vendor:publish --tag=statamic-leadhub --force --no-interaction 2>&1 | tail -1
ok "Addon assets published → public/vendor/statamic-leadhub/build/"

# ------------------------------------------------------------------
# 3. Demo 'contact' form (Statamic 6 stores forms in resources/forms/)
# ------------------------------------------------------------------
step "Creating demo 'contact' form"
mkdir -p resources/forms resources/blueprints/forms
cat > resources/forms/contact.yaml <<'YAML'
title: Contact
honeypot: zip
YAML
cat > resources/blueprints/forms/contact.yaml <<'YAML'
title: Contact
sections:
  main:
    display: Main
    fields:
      - handle: email
        field: { type: text, input_type: email, display: Email, validate: 'required|email' }
      - handle: first_name
        field: { type: text, display: 'First name' }
      - handle: last_name
        field: { type: text, display: 'Last name' }
      - handle: company
        field: { type: text, display: Company }
      - handle: message
        field: { type: textarea, display: Message }
YAML
"$PHP_BIN" please stache:clear >/dev/null 2>&1 || true
ok "Form 'contact' written (resources/forms + blueprint)"

# ------------------------------------------------------------------
# 4. CP super-user (non-interactive)
# ------------------------------------------------------------------
step "Creating Control Panel super-user"
CP_EMAIL="$CP_EMAIL" CP_PASSWORD="$CP_PASSWORD" php_app '
use Statamic\Facades\User;
$email=getenv("CP_EMAIL");
$u=User::findByEmail($email) ?: User::make()->email($email);
$u->password(getenv("CP_PASSWORD"))->makeSuper()->save();
echo "user_ready\n";
' | grep -q user_ready && ok "Super-user: $CP_EMAIL / $CP_PASSWORD"

# ------------------------------------------------------------------
# 5. Seed LeadHub mapping + sample leads
# ------------------------------------------------------------------
step "Seeding LeadHub form mapping + sample leads"
php_app '
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Contact;
use Statamic\Facades\Form;

FormMapping::query()->updateOrCreate(
    ["form_handle" => "contact"],
    [
        "enabled" => true,
        "email_field" => "email",
        "first_name_field" => "first_name",
        "last_name_field" => "last_name",
        "company_field" => "company",
        "message_field" => "message",
        "default_status" => "new",
        "default_source" => "website",
        "default_tags" => ["demo"],
        "attach_full_submission" => true,
    ]
);

$form = Form::find("contact");
$samples = [
    ["email"=>"jane@example.com","first_name"=>"Jane","last_name"=>"Doe","company"=>"Acme Inc","message"=>"Interested in a demo, please call back."],
    ["email"=>"max@musterfirma.de","first_name"=>"Max","last_name"=>"Mustermann","company"=>"Musterfirma GmbH","message"=>"Bitte um ein Angebot fuer 10 Lizenzen."],
    ["email"=>"sam@startup.io","first_name"=>"Sam","last_name"=>"Rivera","company"=>"Startup.io","message"=>"Do you support webhooks?"],
];
foreach ($samples as $data) {
    if (Contact::query()->where("email", $data["email"])->exists()) { continue; }
    $sub = $form->makeSubmission();
    $sub->data($data);
    $sub->save();
}
echo "contacts=".Contact::count()."\n";
' | while IFS='=' read -r k v; do [ "$k" = "contacts" ] && ok "Total contacts in store: $v"; done

# ------------------------------------------------------------------
# Done
# ------------------------------------------------------------------
echo
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}${BOLD} LeadHub playground ready${NC}"
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════════${NC}"
echo
echo "Start the dev server:"
echo "    cd playground && php artisan serve --host=0.0.0.0 --port=8000"
echo
echo "Control Panel:  http://127.0.0.1:8000/cp"
echo "    Login:      $CP_EMAIL"
echo "    Password:   $CP_PASSWORD"
echo
echo "LeadHub lives in the CP sidebar. The 'contact' form is mapped and"
echo "seeded with sample leads. Submit the public form or re-run this"
echo "script to refresh the demo data."
echo
echo "Develop the addon:"
echo "    • edit src/ in the repo root — PHP changes are live (path repo)"
echo "    • change CP Vue/JS — rebuild assets:"
echo "        npm run build   (in the repo root)  &&  \\"
echo "        cd playground && php artisan vendor:publish --tag=statamic-leadhub --force"
echo "    • run addon tests:  composer test   (from repo root)"
echo
