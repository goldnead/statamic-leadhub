<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Http\Requests\UpdateFormMappingRequest;
use Illuminate\Http\Request;
use Statamic\Facades\Form;

class FormMappingController extends Controller
{
    public function __construct(protected FormMappingRepository $mappings)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub form mappings'), 403);

        $statamicForms = collect(Form::all())->map(fn ($form) => [
            'handle' => $form->handle(),
            'title' => $form->title() ?? $form->handle(),
        ]);

        $existingMappings = $this->mappings->all()->keyBy('form_handle');

        // Auto-create empty mapping rows for any Statamic form without one.
        foreach ($statamicForms as $f) {
            if (! $existingMappings->has($f['handle'])) {
                $mapping = $this->mappings->firstOrCreate($f['handle']);
                $existingMappings->put($f['handle'], $mapping);
            }
        }

        return view('leadhub::forms.index', [
            'forms' => $statamicForms,
            'mappings' => $existingMappings,
        ]);
    }

    public function edit(Request $request, string $formHandle)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub form mappings'), 403);

        $mapping = $this->mappings->firstOrCreate($formHandle);

        $form = Form::find($formHandle);
        abort_unless($form, 404, "Statamic form [{$formHandle}] not found.");

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
        $mapping = $this->mappings->findByHandle($formHandle);
        abort_unless($mapping, 404);

        $data = $request->validated();
        $data['form_handle'] = $formHandle;

        $this->mappings->update($mapping, $data);

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->mappings->findByHandle($formHandle)]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.forms.edit', $formHandle)
            ->with('success', __('leadhub::forms.flashes.saved'));
    }

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
            // Fall through to empty.
        }

        return $fields;
    }
}
