<?php

namespace Goldnead\Leadhub\Support;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Panels that other addons contribute to the contact screen.
 *
 * The contact page is where somebody goes to answer "who is this person and
 * what is going on with them". Plenty of that lives outside LeadHub: which
 * mailing lists they are on (marketing), which automations they are enrolled in
 * (automations), what a webhook last told us about them. Until now none of it
 * could appear here, because the alternative was for LeadHub to know about its
 * siblings — and the direction of that dependency is the one thing this family
 * keeps straight. Marketing requires LeadHub; LeadHub requires nobody.
 *
 * So the sibling registers, and LeadHub renders whatever it is handed:
 *
 *     LeadHub::registerContactPanel('marketing.subscriptions', function ($contact) {
 *         return [
 *             'heading' => __('Mailing lists'),
 *             'rows' => [...],
 *         ];
 *     });
 *
 * The shape is deliberately dumb — a heading, rows of label/badge/meta, an
 * optional action — rather than a component name or a slot. A registry that
 * accepted markup would make every contributor's Vue build a dependency of this
 * page, and the first one to ship a broken bundle would take the screen with it.
 */
class ContactPanels
{
    /** @var array<string, Closure> */
    protected array $providers = [];

    /**
     * Register a panel under a key. Registering the same key twice replaces the
     * first — a provider booted through both a service provider and a bridge
     * must not produce the panel twice.
     */
    public function register(string $key, Closure $resolver): void
    {
        $this->providers[$key] = $resolver;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Resolve every panel for one contact, in registration order.
     *
     * A provider that throws is logged and skipped, never propagated: this runs
     * while the contact screen renders, and a sibling addon mid-upgrade must not
     * be able to 500 the page a user opened to read a phone number. A provider
     * that returns null (or a panel with no rows and no empty state) is left
     * out — "nothing to say" is a legitimate answer and an empty box is not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forContact(mixed $contact): array
    {
        $panels = [];

        foreach ($this->providers as $key => $resolver) {
            try {
                $panel = $resolver($contact);
            } catch (\Throwable $e) {
                Log::warning("leadhub: the contact panel [{$key}] failed and was left out.", ['exception' => $e]);

                continue;
            }

            if (! is_array($panel) || ($panel['heading'] ?? null) === null) {
                continue;
            }

            $rows = array_values(array_filter(
                (array) ($panel['rows'] ?? []),
                fn ($row) => is_array($row) && ($row['label'] ?? null) !== null,
            ));

            if ($rows === [] && ($panel['empty'] ?? null) === null) {
                continue;
            }

            $panels[] = [
                'key' => $key,
                'heading' => (string) $panel['heading'],
                'description' => isset($panel['description']) ? (string) $panel['description'] : null,
                'empty' => isset($panel['empty']) ? (string) $panel['empty'] : null,
                'rows' => array_map(fn (array $row) => [
                    'label' => (string) $row['label'],
                    'url' => isset($row['url']) ? (string) $row['url'] : null,
                    'meta' => isset($row['meta']) ? (string) $row['meta'] : null,
                    // `color` follows the Badge component's own vocabulary, so a
                    // contributor never has to know this addon's palette.
                    'badge' => isset($row['badge']['text']) ? [
                        'text' => (string) $row['badge']['text'],
                        'color' => (string) ($row['badge']['color'] ?? 'default'),
                    ] : null,
                ], $rows),
                'action' => isset($panel['action']['text'], $panel['action']['url']) ? [
                    'text' => (string) $panel['action']['text'],
                    'url' => (string) $panel['action']['url'],
                ] : null,
            ];
        }

        return $panels;
    }
}
