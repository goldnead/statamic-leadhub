<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'segments',     // [{ id, name, handle, description, is_active, members_count, edit_url, delete_url }]
    'columns',
    'createUrl',
    'previewUrl',
    'canManage',    // bool
    'vocabulary',
]);

const segmentToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function confirmDelete(segment) {
    segmentToDelete.value = segment;
}

function destroy() {
    if (! segmentToDelete.value) return;
    router.delete(segmentToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { segmentToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Segments'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Segments')" icon="tags">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create segment')"
                variant="primary"
            />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Segments are dynamic groups of contacts defined by rules. Membership updates automatically as contacts change.') }}
        </p>

        <Listing
            :items="segments"
            :columns="columns"
            preferences-prefix="leadhub.segments"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.edit_url" class="font-medium text-blue-600 hover:underline">{{ row.name }}</Link>
            </template>

            <template #cell-handle="{ row }">
                <span class="text-xs text-gray-500">{{ row.handle }}</span>
            </template>

            <template #cell-members_count="{ row }">
                <Badge color="default" :text="String(row.members_count)" />
            </template>

            <template #cell-is_active="{ row }">
                <Badge :color="row.is_active ? 'green' : 'default'" :text="row.is_active ? __('Active') : __('Inactive')" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('Edit')"
                    icon="edit"
                    :href="row.edit_url"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('Delete')"
                    icon="trash"
                    @click="confirmDelete(row)"
                />
            </template>
        </Listing>

        <ConfirmationModal
            :open="segmentToDelete !== null"
            :title="__('Delete segment')"
            :body-text="__('Delete this segment? Contacts are not affected, only the segment definition and its membership.')"
            danger
            :button-text="__('Delete')"
            @cancel="segmentToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
