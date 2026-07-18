<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge, Button, DropdownItem } from '@statamic/cms/ui';

const props = defineProps(['tasks', 'columns', 'filter', 'canManage']);

const filters = [
    { value: 'open', label: __('Open') },
    { value: 'today', label: __('Due today') },
    { value: 'overdue', label: __('Overdue') },
    { value: 'done', label: __('Done') },
];

function setFilter(value) {
    router.get(window.location.pathname, { filter: value }, { preserveState: true, preserveScroll: true });
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function priorityColor(p) {
    return p === 'high' ? 'red' : p === 'low' ? 'default' : 'blue';
}

function complete(row) {
    router.post(row.complete_url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Tasks'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Tasks')" icon="tasks" />

        <div class="flex gap-1 mb-4">
            <Button
                v-for="f in filters"
                :key="f.value"
                :text="f.label"
                size="sm"
                :variant="filter === f.value ? 'primary' : 'ghost'"
                @click="setFilter(f.value)"
            />
        </div>

        <Listing
            :items="tasks"
            :columns="columns"
            preferences-prefix="leadhub.tasks"
            @refreshing="reloadPage"
        >
            <template #cell-title="{ row }">
                <span class="font-medium">{{ row.title }}</span>
            </template>

            <template #cell-contact_name="{ row }">
                <a v-if="row.contact_url" :href="row.contact_url" class="text-blue-600 text-sm">{{ row.contact_name }}</a>
                <span v-else class="text-gray-400">—</span>
            </template>

            <template #cell-priority="{ row }">
                <Badge :color="priorityColor(row.priority)" :text="row.priority" />
            </template>

            <template #cell-due_at="{ row }">
                <span :class="row.is_overdue ? 'text-red-600 font-medium' : 'text-sm'">
                    {{ row.due_at || '—' }}
                </span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage && row.status !== 'done'"
                    :text="__('Mark complete')"
                    icon="check"
                    @click="complete(row)"
                />
            </template>
        </Listing>
    </div>
</template>
