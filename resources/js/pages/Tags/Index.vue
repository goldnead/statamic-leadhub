<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, Field, Input, Panel, PanelHeader, Card, Heading, Stack,
    DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'tags',         // [{ id, name, slug, color, contacts_count, delete_url }]
    'columns',
    'storeUrl',     // POST to create
    'canManage',    // bool
]);

// Create and edit share one Stack. The screen had an inline create form and
// no way at all to rename a tag, even though the PATCH route has existed since
// v1.0 — a typo in a tag name was permanent.
const open = ref(false);
const editing = ref(null);
const form = ref({ name: '', color: '#e5e7eb' });
const errors = ref({});
const saving = ref(false);
const tagToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function startCreate() {
    editing.value = null;
    form.value = { name: '', color: '#e5e7eb' };
    errors.value = {};
    open.value = true;
}

function startEdit(tag) {
    editing.value = tag;
    form.value = { name: tag.name, color: tag.color || '#e5e7eb' };
    errors.value = {};
    open.value = true;
}

function save() {
    if (saving.value || ! form.value.name.trim()) return;
    saving.value = true;
    errors.value = {};

    const done = {
        preserveScroll: true,
        onSuccess: () => { open.value = false; reloadPage(); },
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { saving.value = false; },
    };

    editing.value
        ? router.patch(editing.value.update_url, form.value, done)
        : router.post(props.storeUrl, form.value, done);
}

function confirmDelete(tag) {
    tagToDelete.value = tag;
}

function destroy() {
    if (! tagToDelete.value) return;
    router.delete(tagToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { tagToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Tags'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Tags')" icon="fieldtype-taggable">
            <Button
                v-if="canManage"
                :text="__('New tag')"
                icon="plus"
                variant="primary"
                data-leadhub-new-tag
                @click="startCreate"
            />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Tags help segment contacts. They can be applied manually or automatically via form mappings.') }}
        </p>

        <Listing
            :items="tags"
            :columns="columns"
            preferences-prefix="leadhub.tags"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
            </template>

            <template #cell-slug="{ row }">
                <span class="text-xs text-gray-500">{{ row.slug }}</span>
            </template>

            <template #cell-color="{ row }">
                <!--
                    Kein Tag-Color gesetzt: Platzhalter ueber CP-Tokens statt
                    ueber ein hartkodiertes #e5e7eb. Der Hex war ein fester
                    Light-Mode-Grauwert und blieb im Darkmode unveraendert
                    hell. bg-gray-200/dark:bg-gray-700 folgt dem Theme.
                    Ist eine Farbe gesetzt, gewinnt das Inline-Style ueber die
                    Klasse -- der Swatch zeigt also weiterhin exakt row.color.
                -->
                <span
                    class="inline-block w-6 h-6 rounded border border-content-border bg-gray-200 dark:bg-gray-700"
                    :style="row.color ? { background: row.color } : null"
                ></span>
            </template>

            <template #cell-contacts_count="{ row }">
                <Badge color="default" :text="String(row.contacts_count)" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('Edit')"
                    icon="edit"
                    @click="startEdit(row)"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('Delete')"
                    icon="trash"
                    variant="destructive"
                    @click="confirmDelete(row)"
                />
            </template>
        </Listing>

        <Stack
            v-model:open="open"
            size="narrow"
            :title="editing ? __('Edit') : __('New tag')"
            icon="fieldtype-taggable"
        >
            <Panel>
                <PanelHeader>
                    <Heading :text="editing ? editing.name : __('New tag')" />
                </PanelHeader>
                <Card class="space-y-4">
                    <Field :label="__('New tag name')" :error="errors.name" required>
                        <Input v-model="form.name" :placeholder="__('e.g. VIP')" />
                    </Field>
                    <Field :label="__('Color')" :error="errors.color">
                        <input
                            type="color"
                            v-model="form.color"
                            class="h-10 w-16 rounded border border-content-border"
                        />
                    </Field>
                </Card>
            </Panel>

            <div class="flex gap-2">
                <Button
                    variant="primary"
                    :text="__('Save')"
                    :loading="saving"
                    :disabled="!form.name.trim()"
                    @click="save"
                />
                <Button variant="ghost" :text="__('Cancel')" @click="open = false" />
            </div>
        </Stack>

        <ConfirmationModal
            :open="tagToDelete !== null"
            :title="__('Delete tag')"
            :body-text="__('Delete this tag and detach it from all contacts?')"
            danger
            :button-text="__('Delete')"
            @cancel="tagToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
