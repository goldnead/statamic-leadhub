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
 *
 * An action comes in two shapes, both still data:
 *
 *     'action' => ['text' => 'Manage lists', 'url' => '/cp/…']
 *
 * is a link. And, for "do the thing from here" without LeadHub learning what
 * the thing is:
 *
 *     'action' => [
 *         'text' => 'Add to list',
 *         'icon' => 'plus',
 *         'select' => [
 *             'placeholder' => 'Pick a list…',
 *             'options' => [[
 *                 'value' => 'chorbrief',
 *                 'label' => 'Der Chorbrief',
 *                 'url' => '/cp/marketing/lists/chorbrief/subscribers',
 *                 'payload' => ['email' => 'someone@example.com'],
 *             ]],
 *         ],
 *     ]
 *
 * renders a picker plus a button; the chosen option says where it posts and
 * what it sends. LeadHub posts what it is handed and redirects back.
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
                'action' => $this->action($panel['action'] ?? null),
            ];
        }

        return $panels;
    }

    /**
     * Normalise a panel's action, or null when it is not usable.
     *
     * Both shapes are validated rather than passed through: what a contributor
     * hands over lands in a Vue template on somebody's contact screen, and a
     * half-filled action renders as a button that does nothing.
     *
     * @return array<string, mixed>|null
     */
    protected function action(mixed $action): ?array
    {
        if (! is_array($action) || ! isset($action['text'])) {
            return null;
        }

        $icon = isset($action['icon']) ? (string) $action['icon'] : null;

        // Select-shaped: a picker plus a button, each option carrying the URL
        // it posts to and the body it sends.
        if (isset($action['select']['options']) && is_array($action['select']['options'])) {
            $options = [];

            foreach ($action['select']['options'] as $option) {
                if (! is_array($option) || ! isset($option['value'], $option['label'], $option['url'])) {
                    continue;
                }

                $options[] = [
                    'value' => (string) $option['value'],
                    'label' => (string) $option['label'],
                    'url' => (string) $option['url'],
                    'payload' => is_array($option['payload'] ?? null) ? $option['payload'] : [],
                ];
            }

            if ($options === []) {
                return null;
            }

            return [
                'text' => (string) $action['text'],
                'icon' => $icon,
                'select' => [
                    'placeholder' => isset($action['select']['placeholder'])
                        ? (string) $action['select']['placeholder']
                        : null,
                    'options' => $options,
                ],
            ];
        }

        if (! isset($action['url'])) {
            return null;
        }

        return [
            'text' => (string) $action['text'],
            'icon' => $icon,
            'url' => (string) $action['url'],
        ];
    }
}
