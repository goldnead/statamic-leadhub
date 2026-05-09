<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Http\Requests\UpdateFormMappingRequest;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Support\FormMappingBlueprint;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Facades\Form;

class FormMappingController extends Controller
{
    public function __construct(protected FormMappingRepository $mappings)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('manage leadhub form mappings'), 403);

        // Make sure every Statamic form has a mapping row (auto-bootstrapped).
        $statamicForms = collect(Form::all());
        foreach ($statamicForms as $form) {
            $this->mappings->firstOrCreate($form->handle());
        }

        $mappings = $this->mappings->all()->keyBy('form_handle');

        $rows = $statamicForms->map(function ($form) use ($mappings) {
            $mapping = $mappings->get($form->handle());

            return [
                'handle' => $form->handle(),
                'title' => method_exists($form, 'title') ? ($form->title() ?? $form->handle()) : $form->handle(),
                'enabled' => (bool) ($mapping?->enabled ?? false),
                'email_field' => $mapping?->email_field,
                'last_processed_at' => $mapping?->last_processed_at?->diffForHumans(),
                'edit_url' => cp_route('leadhub.forms.edit', $form->handle()),
            ];
        })->values()->all();

        $columns = collect([
            Column::make('title')->label(__('Form'))->sortable(),
            Column::make('enabled')->label(__('leadhub::forms.enabled')),
            Column::make('email_field')->label(__('leadhub::forms.mapping.email_field')),
            Column::make('last_processed_at')->label(__('leadhub::forms.last_processed')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Forms/Index', [
            'forms' => $rows,
            'columns' => $columns,
        ]);
    }

    public function edit(Request $request, string $formHandle)
    {
        abort_unless($request->user()?->can('manage leadhub form mappings'), 403);

        $mapping = $this->mappings->firstOrCreate($formHandle);
        $form = Form::find($formHandle);
        abort_unless($form, 404, "Statamic form [{$formHandle}] not found.");

        $blueprint = FormMappingBlueprint::build($form);
        $values = $this->mappingToValues($mapping);
        $fields = $blueprint->fields()->addValues($values)->preProcess();

        return Inertia::render('leadhub::Forms/Edit', [
            'title' => method_exists($form, 'title') ? ($form->title() ?? $formHandle) : $formHandle,
            'formHandle' => $formHandle,
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta(),
            'submitUrl' => cp_route('leadhub.forms.update', $formHandle),
            'indexUrl' => cp_route('leadhub.forms.index'),
        ]);
    }

    public function update(UpdateFormMappingRequest $request, string $formHandle)
    {
        $mapping = $this->mappings->findByHandle($formHandle);
        abort_unless($mapping, 404);

        $data = $request->validated();
        $data['form_handle'] = $formHandle;

        $this->mappings->update($mapping, $data);

        return back()->with('success', __('leadhub::forms.flashes.saved'));
    }

    protected function mappingToValues(FormMapping $mapping): array
    {
        return [
            'enabled' => (bool) $mapping->enabled,
            'email_field' => $mapping->email_field,
            'first_name_field' => $mapping->first_name_field,
            'last_name_field' => $mapping->last_name_field,
            'full_name_field' => $mapping->full_name_field,
            'phone_field' => $mapping->phone_field,
            'company_field' => $mapping->company_field,
            'message_field' => $mapping->message_field,
            'consent_field' => $mapping->consent_field,
            'tags_field' => $mapping->tags_field,
            'default_status' => $mapping->default_status ?: config('leadhub.default_status'),
            'default_source' => $mapping->default_source,
            'default_tags' => is_array($mapping->default_tags) ? $mapping->default_tags : [],
            'attach_full_submission' => (bool) $mapping->attach_full_submission,
        ];
    }
}
