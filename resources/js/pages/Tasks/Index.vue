<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge, Button, Checkbox, Select, DropdownItem, ConfirmationModal, CommandPaletteItem } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps([
    'tasks', 'columns', 'filter', 'canManage', 'canComplete',
    'assignableUsers',   // [{ value, label }]
    'assigneeFilter',    // '' | '<id>' | 'none'
    'mine',              // bool
    'currentUserId',
    'createUrl',
]);

const errors = ref({});
const taskToDelete = ref(null);

const filters = [
    { value: 'open', label: __('Open') },
    { value: 'today', label: __('Due today') },
    { value: 'overdue', label: __('Overdue') },
    { value: 'done', label: __('Completed') },
];

// Assignee options mirror the contact list's owner filter: everybody, a named
// user, or explicitly nobody. Without the last one there is no way to find the
// work that fell through the cracks.
const assigneeOptions = computed(() => [
    { value: '', label: __('Anyone') },
    { value: 'none', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

function currentParams() {
    return {
        filter: props.filter,
        ...(props.mine ? { mine: 1 } : {}),
        ...(!props.mine && props.assigneeFilter ? { assignee_id: props.assigneeFilter } : {}),
    };
}

function setFilter(value) {
    navigate({ ...currentParams(), filter: value });
}

function setAssignee(value) {
    const params = { filter: props.filter };
    if (value) params.assignee_id = value;
    navigate(params);
}

function toggleMine() {
    const params = { filter: props.filter };
    if (!props.mine) params.mine = 1;
    navigate(params);
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function priorityColor(p) {
    return p === 'high' ? 'red' : p === 'low' ? 'default' : 'blue';
}

function complete(row) {
    router.post(row.complete_url, {}, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
    });
}

function confirmDelete(row) {
    taskToDelete.value = row;
}

function destroy() {
    if (! taskToDelete.value) return;

    router.delete(taskToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { taskToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Tasks'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Tasks')" icon="clipboard-check">
            <CommandPaletteItem
                v-if="createUrl"
                category="Actions"
                :text="__('New task')"
                icon="clipboard-check"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button
                    :text="text"
                    icon="plus"
                    variant="primary"
                    data-leadhub-new-task
                    @click="router.visit(url)"
                />
            </CommandPaletteItem>
        </Header>

        <ErrorSummary :errors="errors" />

        <div class="flex flex-wrap items-center gap-2 mb-4">
            <div class="flex gap-1">
                <Button
                    v-for="f in filters"
                    :key="f.value"
                    :text="f.label"
                    size="sm"
                    :variant="filter === f.value ? 'primary' : 'ghost'"
                    @click="setFilter(f.value)"
                />
            </div>

            <div class="flex items-center gap-2 ms-auto">
                <Button
                    :text="__('My tasks')"
                    size="sm"
                    :variant="mine ? 'primary' : 'ghost'"
                    data-leadhub-my-tasks
                    @click="toggleMine"
                />
                <Select
                    :model-value="mine ? '' : assigneeFilter"
                    :options="assigneeOptions"
                    class="w-56"
                    data-leadhub-assignee-filter
                    @update:model-value="setAssignee"
                />
            </div>
        </div>

        <Listing
            :items="tasks"
            :columns="columns"
            preferences-prefix="leadhub.tasks"
            @refreshing="reloadPage"
        >
            <!--
                The title opens the task, the way every other listing in the CP
                opens its record. It was plain text before, so the only way in
                was the "…" menu.

                The checkbox in front of it does what a task list is for: tick
                it off where you are looking at it. Hiding "mark complete"
                behind the row menu made the commonest action the hardest one.
            -->
            <template #cell-title="{ row }">
                <div class="flex items-center gap-2.5">
                    <!-- `solo` is the prop for exactly this: a checkbox in a
                         table cell, where the label sits beside it. Without
                         it the component prints its own value ("false") where
                         the label would go. -->
                    <Checkbox
                        v-if="canComplete"
                        solo
                        :model-value="row.status === 'done'"
                        :disabled="row.status === 'done'"
                        :aria-label="__('Mark complete')"
                        data-leadhub-task-done
                        @update:model-value="(checked) => checked && complete(row)"
                    />
                    <a
                        v-if="row.edit_url"
                        :href="row.edit_url"
                        class="font-medium hover:underline"
                        :class="row.status === 'done' ? 'line-through text-gray-500' : ''"
                    >{{ row.title }}</a>
                    <span v-else class="font-medium">{{ row.title }}</span>
                </div>
            </template>

            <template #cell-contact_name="{ row }">
                <a v-if="row.contact_url" :href="row.contact_url" class="text-primary text-sm">{{ row.contact_name }}</a>
                <span v-else class="text-gray-400">—</span>
            </template>

            <template #cell-assignee_name="{ row }">
                <span
                    v-if="row.assignee_name"
                    class="text-sm"
                    :class="String(row.assignee_id) === String(currentUserId) ? 'font-medium' : ''"
                    data-leadhub-task-assignee-cell
                >{{ row.assignee_name }}</span>
                <span v-else class="text-xs text-gray-400" data-leadhub-task-assignee-cell>{{ __('Unassigned') }}</span>
            </template>

            <template #cell-priority="{ row }">
                <Badge :color="priorityColor(row.priority)" :text="row.priority_label || row.priority" />
            </template>

            <template #cell-due_at="{ row }">
                <span :class="row.is_overdue ? 'text-red-600 font-medium' : 'text-sm'">
                    {{ row.due_at || '—' }}
                </span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canComplete && row.status !== 'done'"
                    :text="__('Mark complete')"
                    icon="checkmark"
                    @click="complete(row)"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('Edit')"
                    icon="edit"
                    @click="router.visit(row.edit_url)"
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

        <ConfirmationModal
            :open="taskToDelete !== null"
            :title="__('Delete task')"
            :body-text="__('Delete this task? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="taskToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
