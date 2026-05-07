<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\UpdateFormMappingRequest;
use Goldnead\Leadhub\Models\FormMapping;
use Illuminate\Http\Request;
use Statamic\Facades\Form;

class FormMappingController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub form mappings'), 403);

        // All Statamic forms (handle => Form instance), ensure each has a mapping row.
        $statamicForms = collect(Form::all())->map(function ($form) {
            return [
                'handle' => $form->handle(),
                'title' => $form->title() ?? $form->handle(),
            ];
        });

        $mappings = FormMapping::query()->get()->keyBy('form_handle');

        // Auto-create empty mapping rows for any Statamic form without one,
        // so the UI can render a "Configure" button consistently.
        foreach ($statamicForms as $f) {
            if (! $mappings->has($f['handle'])) {
                $mapping = FormMapping::create([
                    'form_handle' => $f['handle'],
                    'enabled' => false,
                ]);
                $mappings->put($f['handle'], $mapping);
            }
        }

        return view('leadhub::forms.index', [
            'forms' => $statamicForms,
            'mappings' => $mappings,
        ]);
    }

    public function edit(Request $request, string $formHandle)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub form mappings'), 403);

        $mapping = FormMapping::query()
            ->firstOrCreate(
                ['form_handle' => $formHandle],
                ['enabled' => false]
            );

        $form = Form::find($formHandle);

        if (! $form) {
            abort(404, "Statamic form [{$formHandle}] not found.");
        }

        $fields = $this->extractFormFields($form);

        return view('leadhub::forms.edit', [
            'mapping' => $mapping,
            'form' => $form,
            'formHandle' => $formHandle,
            'formTitle' => method_exists($form, 'title') ? ($form->title() ?? $formHandle) : $formHandle,
            'fields' => $fields,
            'statuses' => (array) config('leadhub.statuses', []),
        ]);
    }

    public function update(UpdateFormMappingRequest $request, string $formHandle)
    {
        $mapping = FormMapping::query()
            ->where('form_handle', $formHandle)
            ->firstOrFail();

        $data = $request->validated();
        $mapping->fill($data);
        $mapping->form_handle = $formHandle;
        $mapping->save();

        if ($request->expectsJson()) {
            return response()->json(['data' => $mapping->fresh()]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.forms.edit', $formHandle)
            ->with('success', __('leadhub::forms.flashes.saved'));
    }

    /**
     * Extract a flat list of [handle => display] pairs from a Statamic form's blueprint.
     * Tolerates older Statamic API shapes by falling back to fields().
     */
    protected function extractFormFields($form): array
    {
        $fields = [];

        try {
            $blueprint = method_exists($form, 'blueprint') ? $form->blueprint() : null;

            if ($blueprint && method_exists($blueprint, 'fields')) {
                foreach ($blueprint->fields()->all() as $field) {
                    $handle = $field->handle();
                    $display = method_exists($field, 'display') ? ($field->display() ?: $handle) : $handle;
                    $fields[$handle] = $display;
                }
            }
        } catch (\Throwable) {
            // Fall through to the empty-fields case.
        }

        return $fields;
    }
}
