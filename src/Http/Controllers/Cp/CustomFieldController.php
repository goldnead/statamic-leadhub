<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * Defining the fields a site keeps about its own contacts.
 *
 * In the Control Panel and not a config file, deliberately: the person who
 * needs a new field is the person using the CRM, and a field that requires a
 * deploy is a field that never gets added.
 */
class CustomFieldController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        $this->abortUnlessEloquent();

        $canManage = $this->userCan($request, 'manage leadhub settings');

        $felder = CustomField::query()->orderBy('sort')->orderBy('label')->get();

        $rows = $felder->map(fn (CustomField $f): array => [
            'id' => (string) $f->id,
            'handle' => $f->handle,
            'label' => $f->label,
            'type' => $f->type,
            'type_label' => __('leadhub::custom_fields.types.'.$f->type),
            'options' => $f->options ?? [],
            'instructions' => $f->instructions,
            'sort' => (int) $f->sort,
            // How many contacts actually carry a value. Without it a field that
            // nobody ever filled in looks the same as one everybody uses, and
            // the delete confirmation below would be a guess.
            'in_use' => $this->inUse($f->handle),
            'update_url' => cp_route('leadhub.custom-fields.update', $f->id),
            'delete_url' => cp_route('leadhub.custom-fields.destroy', $f->id),
        ])->all();

        $columns = collect([
            Column::make('label')->label(__('leadhub::custom_fields.label')),
            Column::make('handle')->label(__('leadhub::custom_fields.handle')),
            Column::make('type')->label(__('leadhub::custom_fields.type')),
            Column::make('in_use')->label(__('leadhub::custom_fields.in_use')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::CustomFields/Index', [
            'fields' => $rows,
            'columns' => $columns,
            'canManage' => $canManage,
            'storeUrl' => cp_route('leadhub.custom-fields.store'),
            // As {value,label} pairs, resolved here. The Vue side may not
            // build a key by interpolation — TranslationParityTest requires
            // every __() call to carry a single-quoted literal, so that the
            // strings can be harvested and a missing one is a failing test
            // rather than a key printed at a reader.
            'types' => collect(CustomField::types())
                ->map(static fn (string $t): array => ['value' => $t, 'label' => __('leadhub::custom_fields.types.'.$t)])
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        $this->abortUnlessEloquent();

        CustomField::create($this->validated($request));

        return back()->with('success', __('leadhub::custom_fields.flashes.created'));
    }

    public function update(Request $request, int|string $customField)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        $this->abortUnlessEloquent();

        $feld = CustomField::query()->findOrFail($customField);

        // The handle is not in the update rules, and that is the point: every
        // value already written sits under it. Renaming would orphan all of
        // them at once — silently, because the values stay in the row and only
        // stop being readable.
        $feld->fill($this->validated($request, $feld))->save();

        return back()->with('success', __('leadhub::custom_fields.flashes.updated'));
    }

    /**
     * Deleting a definition leaves the values behind.
     *
     * Not out of caution but because the alternative is worse: a delete that
     * also swept every contact's value would be an irreversible data loss
     * behind a button whose label says "delete field". The values become
     * unreadable, which is recoverable by defining the handle again; deleted
     * values are not recoverable at all.
     *
     * The screen says so in the confirmation, and the count above is what makes
     * that sentence honest.
     */
    public function destroy(Request $request, int|string $customField)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        $this->abortUnlessEloquent();

        CustomField::query()->findOrFail($customField)->delete();

        return back()->with('success', __('leadhub::custom_fields.flashes.deleted'));
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?CustomField $bestehend = null): array
    {
        $regeln = [
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', CustomField::types())],
            'options' => ['nullable', 'array'],
            'options.*.value' => ['required_with:options', 'string', 'max:255'],
            'options.*.label' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];

        if (! $bestehend) {
            $regeln['handle'] = [
                'required', 'string', 'max:64',
                // The table has a unique index on (brand_id, handle). Without
                // this rule a duplicate arrives there as a 500 on a screen
                // whose whole error pattern is otherwise a message at the
                // field that was wrong.
                Rule::unique('leadhub_custom_fields', 'handle')
                    ->where('brand_id', BrandContext::currentId()),
                // Lowercase, letters, digits, underscores — it is a JSON key
                // and a rule reference, not a label. A handle with a dot would
                // read as nesting the first time somebody used `Arr::get`.
                'regex:/^[a-z][a-z0-9_]*$/',
            ];
        }

        $daten = $request->validate($regeln);

        if (($daten['type'] ?? null) !== CustomField::TYPE_SELECT) {
            // Options on anything but a select are stored data nothing reads.
            $daten['options'] = null;
        }

        return $daten;
    }

    /**
     * How many contacts carry a value in this field.
     *
     * Counted in the database, not in PHP. The first version read every
     * contact's JSON column into memory once per field: measured at 0.52s and
     * +10MB for 5000 contacts and five fields, and it grows with both — on a
     * real database over the wire, seconds and hundreds of megabytes to display
     * four numbers.
     *
     * `whereJsonContainsKey` works on both SQLite and MySQL, which is what this
     * screen needs; the driver check upstream already keeps the flat-file
     * install away from here.
     */
    protected function inUse(string $handle): int
    {
        return Contact::query()->whereJsonContainsKey('custom_fields->'.$handle)->count();
    }
}
