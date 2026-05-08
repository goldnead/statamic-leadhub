<?php

namespace Goldnead\Leadhub\Support;

use Statamic\Facades\Blueprint;

/**
 * Builds the Blueprint that drives the LeadHub Forms/Edit PublishForm.
 *
 * The Blueprint reflects the Statamic form's actual fields as Select options,
 * so admins pick from real handles instead of typing free-form strings.
 */
class FormMappingBlueprint
{
    public static function build(mixed $statamicForm): \Statamic\Fields\Blueprint
    {
        $fieldOptions = self::extractFormFieldOptions($statamicForm);

        $statuses = collect((array) config('leadhub.statuses', []))
            ->map(fn ($label, $key) => ['key' => $key, 'value' => $label])
            ->values()
            ->mapWithKeys(fn ($row) => [$row['key'] => $row['value']])
            ->all();

        $emptyAware = $fieldOptions; // already includes a sentinel-friendly map

        return Blueprint::make()->setContents([
            'tabs' => [
                'general' => [
                    'display' => __('General'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'enabled',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('leadhub::forms.mapping.enable'),
                                        'instructions' => __('Submissions to this form will create / update LeadHub contacts.'),
                                        'default' => false,
                                    ],
                                ],
                                [
                                    'handle' => 'default_status',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.default_status'),
                                        'options' => $statuses,
                                        'clearable' => false,
                                        'default' => config('leadhub.default_status', 'new'),
                                    ],
                                ],
                                [
                                    'handle' => 'default_source',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('leadhub::forms.mapping.default_source'),
                                        'instructions' => __('Source label stored on the contact (e.g. "website", "newsletter").'),
                                    ],
                                ],
                                [
                                    'handle' => 'default_tags',
                                    'field' => [
                                        'type' => 'taggable',
                                        'display' => __('leadhub::forms.mapping.default_tags'),
                                        'instructions' => __('Tags applied automatically to every contact from this form.'),
                                    ],
                                ],
                                [
                                    'handle' => 'attach_full_submission',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('leadhub::forms.mapping.attach_full_submission'),
                                        'instructions' => __('Store the full submission payload (after redaction) on the timeline event.'),
                                        'default' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'mapping' => [
                    'display' => __('Field Mapping'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.email_field'),
                                        'instructions' => __('Required when LeadHub is enabled.'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'first_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.first_name_field'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'last_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.last_name_field'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'full_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.full_name_field'),
                                        'instructions' => __('If set, will be split into first/last when those are not mapped.'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'phone_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.phone_field'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'company_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.company_field'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'message_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.message_field'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.consent_field'),
                                        'instructions' => __('Boolean field that captures GDPR / mailing consent.'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'tags_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('leadhub::forms.mapping.tags_field'),
                                        'instructions' => __('Comma-separated string or array of tag names.'),
                                        'options' => $emptyAware,
                                        'clearable' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Extracts the Statamic form's field handles + display labels as Select
     * options. Returns an associative ['handle' => 'Display (handle)'] map.
     */
    protected static function extractFormFieldOptions(mixed $form): array
    {
        $options = [];

        try {
            $blueprint = method_exists($form, 'blueprint') ? $form->blueprint() : null;

            if ($blueprint && method_exists($blueprint, 'fields')) {
                foreach ($blueprint->fields()->all() as $field) {
                    $handle = $field->handle();
                    $display = method_exists($field, 'display') ? ($field->display() ?: $handle) : $handle;
                    $options[$handle] = $display.' ('.$handle.')';
                }
            }
        } catch (\Throwable) {
            // Fall through — admins will see an empty Select and can re-create
            // the form blueprint. Better than crashing the page.
        }

        return $options;
    }
}
