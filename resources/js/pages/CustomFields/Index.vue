<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Listing, Badge, Button, Field, Input, Select,
    DropdownItem, ConfirmationModal, Text, Textarea,
} from '@statamic/cms/ui';

const props = defineProps([
    'fields',    // [{ id, handle, label, type, options, instructions, sort, in_use, update_url, delete_url }]
    'columns',
    'canManage', // bool
    'storeUrl',  // string
    'types',     // [{ value, label }] — labels resolved server-side
]);

// The create form. `errors` + onError + `Field :error` is this addon's pattern
// since v1.5.0: a rejected input says what was wrong at the field that was
// wrong, rather than looking like a dead button.
const blank = { handle: '', label: '', type: 'text', options: [], instructions: '', sort: 0 };
const newField = ref({ ...blank });
const errors = ref({});

const editingId = ref(null);
const editField = ref({ ...blank });
const editErrors = ref({});

const fieldToDelete = ref(null);

// Already {value,label}: see the controller for why the labels are resolved
// there and not interpolated into a translation call here: the parity test
// requires every one of those to carry a single-quoted literal.
const typeOptions = props.types;

function reload() {
    router.reload({ preserveScroll: true });
}

// Options are only meaningful for a select; the form hides them elsewhere and
// the controller drops them, so a type changed after the fact cannot leave
// stored data nothing reads.
function isSelect(f) {
    return f.type === 'select';
}

function addOption(f) {
    f.options = [...(f.options || []), { value: '', label: '' }];
}

function removeOption(f, i) {
    f.options = f.options.filter((_, n) => n !== i);
}

function create() {
    errors.value = {};
    router.post(props.storeUrl, newField.value, {
        preserveScroll: true,
        onSuccess: () => { newField.value = { ...blank, options: [] }; reload(); },
        onError: (e) => { errors.value = e; },
    });
}

function startEdit(row) {
    editingId.value = row.id;
    editErrors.value = {};
    editField.value = {
        label: row.label, type: row.type,
        options: (row.options || []).map((o) => ({ ...o })),
        instructions: row.instructions || '', sort: row.sort,
    };
}

function saveEdit(row) {
    editErrors.value = {};
    router.patch(row.update_url, editField.value, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; reload(); },
        onError: (e) => { editErrors.value = e; },
    });
}

function destroy() {
    const row = fieldToDelete.value;
    fieldToDelete.value = null;
    router.delete(row.delete_url, { preserveScroll: true, onSuccess: reload });
}
</script>

<template>
    <Head :title="__('leadhub::custom_fields.title')" />

    <Header :title="__('leadhub::custom_fields.title')" icon="list-bullets" />

    <Panel>
        <Text class="mb-4 text-gray-600 dark:text-gray-400">
            {{ __('leadhub::custom_fields.description') }}
        </Text>

        <Card v-if="canManage" class="mb-6">
            <div class="grid items-end gap-4 md:grid-cols-4">
                <Field :label="__('leadhub::custom_fields.label')" :error="errors.label">
                    <Input v-model="newField.label" />
                </Field>
                <Field
                    :label="__('leadhub::custom_fields.handle')"
                    :instructions="__('leadhub::custom_fields.handle_hint')"
                    :error="errors.handle"
                >
                    <Input v-model="newField.handle" />
                </Field>
                <Field :label="__('leadhub::custom_fields.type')" :error="errors.type">
                    <Select v-model="newField.type" :options="typeOptions" />
                </Field>
                <Field :label="__('leadhub::custom_fields.instructions')" :error="errors.instructions">
                    <Input v-model="newField.instructions" />
                </Field>
            </div>

            <div v-if="isSelect(newField)" class="mt-4">
                <Field :label="__('leadhub::custom_fields.options')">
                    <div v-for="(o, i) in newField.options" :key="i" class="mb-2 flex gap-2">
                        <Input v-model="o.value" placeholder="wert" />
                        <Input v-model="o.label" placeholder="Bezeichnung" />
                        <Button variant="ghost" size="sm" @click="removeOption(newField, i)">&times;</Button>
                    </div>
                    <Button variant="ghost" size="sm" @click="addOption(newField)">+</Button>
                </Field>
            </div>

            <div class="mt-4">
                <Button variant="primary" @click="create">{{ __('leadhub::custom_fields.add') }}</Button>
            </div>
        </Card>

        <Listing :items="fields" :columns="columns">
            <template #cell-type="{ row }">
                <Badge :text="row.type_label" />
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
            </template>

            <!-- A field nobody ever filled in looks the same as one everybody
                 uses without this number — and the delete confirmation would be
                 a guess. -->
            <template #cell-in_use="{ row }">
                <span class="tabular-nums">{{ row.in_use }}</span>
            </template>

            <template v-if="canManage" #actions="{ row }">
                <DropdownItem :text="__('Edit')" @click="startEdit(row)" />
                <DropdownItem :text="__('Delete')" variant="destructive" @click="fieldToDelete = row" />
            </template>

            <template #empty>
                <Text>{{ __('leadhub::custom_fields.empty') }}</Text>
            </template>
        </Listing>

        <Card v-if="editingId" class="mt-6">
            <div class="grid items-end gap-4 md:grid-cols-3">
                <Field :label="__('leadhub::custom_fields.label')" :error="editErrors.label">
                    <Input v-model="editField.label" />
                </Field>
                <Field :label="__('leadhub::custom_fields.type')" :error="editErrors.type">
                    <Select v-model="editField.type" :options="typeOptions" />
                </Field>
                <Field :label="__('leadhub::custom_fields.instructions')" :error="editErrors.instructions">
                    <Input v-model="editField.instructions" />
                </Field>
            </div>

            <div v-if="isSelect(editField)" class="mt-4">
                <Field :label="__('leadhub::custom_fields.options')">
                    <div v-for="(o, i) in editField.options" :key="i" class="mb-2 flex gap-2">
                        <Input v-model="o.value" placeholder="wert" />
                        <Input v-model="o.label" placeholder="Bezeichnung" />
                        <Button variant="ghost" size="sm" @click="removeOption(editField, i)">&times;</Button>
                    </div>
                    <Button variant="ghost" size="sm" @click="addOption(editField)">+</Button>
                </Field>
            </div>

            <div class="mt-4 flex gap-2">
                <Button variant="primary" @click="saveEdit(fields.find((f) => f.id === editingId))">
                    {{ __('Save') }}
                </Button>
                <Button variant="ghost" @click="editingId = null">{{ __('Cancel') }}</Button>
            </div>
        </Card>
    </Panel>

    <ConfirmationModal
        v-if="fieldToDelete"
        :title="__('leadhub::custom_fields.title')"
        :body-text="__('leadhub::custom_fields.delete_confirm')"
        :danger="true"
        @confirm="destroy"
        @cancel="fieldToDelete = null"
    />
</template>
