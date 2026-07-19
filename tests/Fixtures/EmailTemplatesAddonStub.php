<?php

namespace Goldnead\EmailTemplates\Facades;

/*
|--------------------------------------------------------------------------
| Email Templates addon test stub
|--------------------------------------------------------------------------
|
| The real email templates live in the standalone goldnead/statamic-email-
| templates addon, which is NOT a composer dependency of leadhub. LeadHub's
| resolveEmailTemplate() seam delegates to that addon's public facade,
| Goldnead\EmailTemplates\Facades\EmailTemplates::resolve(). To exercise the
| delegation without pulling in the addon, we declare a minimal stand-in for
| that facade here and let the test drive its behaviour via $handler.
|
| Guarded with class_exists so that, on the off chance the real addon IS
| autoloadable in some environment, we never redeclare it.
|
*/
if (! class_exists(EmailTemplates::class, false)) {
    class EmailTemplates
    {
        /** @var (callable(string, ?callable): (object|null))|null */
        public static $handler = null;

        public static function resolve(string $slug, ?callable $fallback = null): ?object
        {
            if (static::$handler === null) {
                return null;
            }

            return (static::$handler)($slug, $fallback);
        }
    }
}
