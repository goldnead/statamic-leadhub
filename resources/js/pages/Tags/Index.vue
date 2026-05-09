<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, Field, Input, Panel, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'tags',         // [{ id, name, slug, color, contacts_count, delete_url }]
    'columns',
    'storeUrl',     // POST to create
    'canManage',    // bool
]);

const newName = ref('');
const newColor = ref('#e5e7eb');
const tagToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function create() {
    if (! newName.value.trim()) return;
    router.post(props.storeUrl, { name: newName.value, color: newColor.value }, {
        preserveScroll: true,
        onSuccess: () => { newName.value = ''; },
    });
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
        <Header :title="__('Tags')" icon="hashtag" />

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Tags help segment contacts. They can be applied manually or automatically via form mappings.') }}
        </p>

        <Panel v-if="canManage" class="mb-4">
            <div class="p-4 flex flex-col sm:flex-row gap-2 items-start sm:items-end">
                <Field :label="__('New tag name')" class="flex-1">
                    <Input v-model="newName" :placeholder="__('e.g. VIP')" />
                </Field>
                <Field :label="__('Color')">
                    <input type="color" v-model="newColor" class="h-10 w-12 rounded border border-content-border" />
                </Field>
                <Button :text="__('Create tag')" variant="primary" :disabled="!newName.trim()" @click="create" />
            </div>
        </Panel>

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
                <span
                    class="inline-block w-6 h-6 rounded border border-content-border"
                    :style="{ background: row.color || '#e5e7eb' }"
                ></span>
            </template>

            <template #cell-contacts_count="{ row }">
                <Badge color="gray" :text="String(row.contacts_count)" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('Delete')"
                    icon="trash"
                    @click="confirmDelete(row)"
                />
            </template>
        </Listing>

        <ConfirmationModal
            v-if="tagToDelete"
            :title="__('Delete tag')"
            :message="__('Delete this tag and detach it from all contacts?')"
            variant="danger"
            :button-text="__('Delete')"
            @cancel="tagToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
