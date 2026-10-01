<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, DropdownItem, ConfirmationModal, CommandPaletteItem,
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
        <Header :title="__('Segments')" icon="filter">
            <CommandPaletteItem
                v-if="canManage"
                category="Actions"
                :text="__('Create segment')"
                icon="filter"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button :href="url" :text="text" variant="primary" />
            </CommandPaletteItem>
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
                <div class="flex flex-col items-start gap-1">
                    <Link :href="row.edit_url" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                    <!-- Another addon maintains this one (a concert series'
                         radius segment). The badge links to the owner, where
                         the rule is actually changed. -->
                    <Badge
                        v-if="row.managed_by"
                        pill
                        color="purple"
                        icon="padlock-locked"
                        :href="row.managed_by.url || undefined"
                        :text="__('Managed by :name', { name: row.managed_by.label || row.managed_by.source })"
                        data-leadhub-managed-badge
                    />
                </div>
            </template>

            <template #cell-handle="{ row }">
                <!-- A series handle carries a UUID; wrapped over two lines it
                     doubles every row's height. Cut, with the whole in the title. -->
                <span class="block max-w-[16rem] truncate font-mono text-xs text-gray-500 dark:text-gray-400" :title="row.handle">{{ row.handle }}</span>
            </template>

            <template #cell-members_count="{ row }">
                <span class="tabular-nums">{{ row.members_count }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <Badge pill :color="row.is_active ? 'green' : 'default'" :text="row.is_active ? __('Active') : __('Inactive')" />
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
