<script setup>
/**
 * Custom fields.
 *
 * The screen used to carry two inline forms — one above the table to add a
 * field, one below it to edit the row you had picked. The CP does neither:
 * creating and editing a record happens on its own surface, and the listing
 * stays a listing. Both now open the same `Stack` from the right, the way
 * the listing's own filters do.
 *
 * The edit action existed before but sat in a `#actions` slot, which `Listing`
 * does not have — so it rendered nowhere and no field could be edited at all.
 * The slot is `prepended-row-actions`.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, PanelHeader, Card, Heading, Stack, Listing, Badge, Button,
    Field, Input, Select, DropdownItem, ConfirmationModal, Text,
} from '@statamic/cms/ui';

const props = defineProps([
    'fields',    // [{ id, handle, label, type, type_label, options, instructions, sort, in_use, update_url, delete_url }]
    'columns',
    'canManage', // bool
    'storeUrl',  // string
    'types',     // [{ value, label }] — labels resolved server-side
]);

const blank = { handle: '', label: '', type: 'text', options: [], instructions: '', sort: 0 };

const open = ref(false);
/** The row being edited, or null while creating. */
const editing = ref(null);
const form = ref({ ...blank, options: [] });
const errors = ref({});
const saving = ref(false);

const typeOptions = props.types;
const isSelect = computed(() => form.value.type === 'select');

function startCreate() {
    editing.value = null;
    form.value = { ...blank, options: [] };
    errors.value = {};
    open.value = true;
}

function startEdit(row) {
    editing.value = row;
    form.value = {
        handle: row.handle,
        label: row.label,
        type: row.type,
        options: (row.options || []).map((o) => ({ ...o })),
        instructions: row.instructions || '',
        sort: row.sort,
    };
    errors.value = {};
    open.value = true;
}

function addOption() {
    form.value.options = [...(form.value.options || []), { value: '', label: '' }];
}

function removeOption(i) {
    form.value.options = form.value.options.filter((_, n) => n !== i);
}

function save() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};

    const done = {
        preserveScroll: true,
        onSuccess: () => { open.value = false; router.reload({ preserveScroll: true }); },
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { saving.value = false; },
    };

    editing.value
        ? router.patch(editing.value.update_url, form.value, done)
        : router.post(props.storeUrl, form.value, done);
}

const fieldToDelete = ref(null);

function destroy() {
    const row = fieldToDelete.value;
    fieldToDelete.value = null;
    router.delete(row.delete_url, {
        preserveScroll: true,
        onSuccess: () => router.reload({ preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="__('leadhub::custom_fields.title')" />

    <div class="max-w-page mx-auto">
        <Header :title="__('leadhub::custom_fields.title')" icon="list-ul">
            <Button
                v-if="canManage"
                :text="__('leadhub::custom_fields.add')"
                icon="plus"
                variant="primary"
                data-leadhub-new-custom-field
                @click="startCreate"
            />
        </Header>

        <Text class="mb-4 block text-gray-600 dark:text-gray-400">
            {{ __('leadhub::custom_fields.description') }}
        </Text>

        <Listing :items="fields" :columns="columns" preferences-prefix="leadhub.custom-fields">
            <template #cell-label="{ row }">
                <span class="font-medium">{{ row.label }}</span>
            </template>

            <template #cell-type="{ row }">
                <Badge pill :text="row.type_label" />
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

            <template v-if="canManage" #prepended-row-actions="{ row }">
                <DropdownItem :text="__('Edit')" icon="edit" @click="startEdit(row)" />
                <DropdownItem
                    :text="__('Delete')"
                    icon="trash"
                    variant="destructive"
                    @click="fieldToDelete = row"
                />
            </template>
        </Listing>

        <Stack
            v-model:open="open"
            size="narrow"
            :title="editing ? __('Edit') : __('leadhub::custom_fields.add')"
            icon="list-ul"
        >
            <Panel>
                <PanelHeader>
                    <Heading :text="editing ? editing.label : __('leadhub::custom_fields.add')" />
                </PanelHeader>
                <Card class="space-y-4">
                    <Field :label="__('leadhub::custom_fields.label')" :error="errors.label" required>
                        <Input v-model="form.label" />
                    </Field>

                    <!-- The handle is what stored values hang on, so it is
                         set once and read-only afterwards. -->
                    <Field
                        :label="__('leadhub::custom_fields.handle')"
                        :instructions="__('leadhub::custom_fields.handle_hint')"
                        :error="errors.handle"
                    >
                        <Input v-model="form.handle" :read-only="!!editing" :disabled="!!editing" />
                    </Field>

                    <Field :label="__('leadhub::custom_fields.type')" :error="errors.type">
                        <Select v-model="form.type" :options="typeOptions" class="w-full" adaptive-width />
                    </Field>

                    <Field :label="__('leadhub::custom_fields.instructions')" :error="errors.instructions">
                        <Input v-model="form.instructions" />
                    </Field>

                    <Field v-if="isSelect" :label="__('leadhub::custom_fields.options')">
                        <div class="space-y-2">
                            <div v-for="(o, i) in form.options" :key="i" class="flex gap-2">
                                <Input v-model="o.value" :placeholder="__('Value')" />
                                <Input v-model="o.label" :placeholder="__('Label')" />
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    :aria-label="__('Remove')"
                                    @click="removeOption(i)"
                                />
                            </div>
                            <Button size="sm" icon="plus" :text="__('Add')" @click="addOption" />
                        </div>
                    </Field>
                </Card>
            </Panel>

            <div class="flex gap-2">
                <Button
                    variant="primary"
                    :text="__('Save')"
                    :loading="saving"
                    :disabled="!String(form.label || '').trim()"
                    @click="save"
                />
                <Button variant="ghost" :text="__('Cancel')" @click="open = false" />
            </div>
        </Stack>

        <ConfirmationModal
            :open="fieldToDelete !== null"
            :title="__('leadhub::custom_fields.title')"
            :body-text="__('leadhub::custom_fields.delete_confirm')"
            danger
            :button-text="__('Delete')"
            @confirm="destroy"
            @cancel="fieldToDelete = null"
        />
    </div>
</template>
