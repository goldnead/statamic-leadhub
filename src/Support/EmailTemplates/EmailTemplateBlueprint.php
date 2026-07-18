<?php

namespace Goldnead\Leadhub\Support\EmailTemplates;

use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * The blueprint for the shared `email_templates` collection.
 *
 * HTML-mail compatibility drives the field choices: the body is a `code`
 * field (raw HTML), NOT a Bard field, so the stored markup renders 1:1 in
 * email clients without Statamic wrapping it in <p> tags or stripping inline
 * styles. `plain_text` is the optional text/plain multipart alternative.
 */
class EmailTemplateBlueprint
{
    public const HANDLE = 'email_template';

    public const NAMESPACE = 'collections.email_templates';

    public static function make(): BlueprintInstance
    {
        return Blueprint::make(self::HANDLE)
            ->setNamespace(self::NAMESPACE)
            ->setContents([
                'title' => __('leadhub::email_templates.blueprint_title'),
                'tabs' => [
                    'main' => [
                        'display' => __('leadhub::email_templates.tab_content'),
                        'sections' => [
                            [
                                'fields' => [
                                    [
                                        'handle' => 'title',
                                        'field' => [
                                            'type' => 'text',
                                            'required' => true,
                                            'validate' => ['required'],
                                            'display' => __('leadhub::email_templates.field_title'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'subject',
                                        'field' => [
                                            'type' => 'text',
                                            'display' => __('leadhub::email_templates.field_subject'),
                                            'instructions' => __('leadhub::email_templates.field_subject_instructions'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'body',
                                        'field' => [
                                            'type' => 'code',
                                            'mode' => 'htmlmixed',
                                            'theme' => 'material',
                                            'indent_type' => 'spaces',
                                            'indent_size' => 2,
                                            'display' => __('leadhub::email_templates.field_body'),
                                            'instructions' => __('leadhub::email_templates.field_body_instructions'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'plain_text',
                                        'field' => [
                                            'type' => 'textarea',
                                            'display' => __('leadhub::email_templates.field_plain_text'),
                                            'instructions' => __('leadhub::email_templates.field_plain_text_instructions'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'description',
                                        'field' => [
                                            'type' => 'textarea',
                                            'display' => __('leadhub::email_templates.field_description'),
                                            'instructions' => __('leadhub::email_templates.field_description_instructions'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
