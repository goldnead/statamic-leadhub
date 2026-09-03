<script setup>
/**
 * Follow-ups.
 *
 * Was three panels of cards — overdue, today, upcoming — which the Control
 * Panel does nowhere: a list of records is a `Listing`, and a listing is what
 * gives you search, sortable columns, row actions and a column picker for
 * free. The three groups survive as a column and as counts above the table.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, Icon, DropdownItem,
    EmptyStateMenu, EmptyStateItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'followups',        // [{ id, contact_name, contact_url, due_at, note, bucket,
                        //   bucket_label, complete_url, delete_url }]
    'columns',
    'counts',           // { overdue, today, upcoming }
    'configureFormsUrl',
    'hasFormConnected',
]);

const isEmpty = computed(() => (props.followups || []).length === 0);

/** Which group is showing. `null` is everything, which is the default. */
const bucket = ref(null);

const visible = computed(() =>
    bucket.value ? props.followups.filter((f) => f.bucket === bucket.value) : props.followups
);

const buckets = computed(() => [
    { value: null, label: __('All'), count: (props.followups || []).length },
    { value: 'overdue', label: __('leadhub::followups.sections.overdue'), count: props.counts?.overdue ?? 0 },
    { value: 'today', label: __('leadhub::followups.sections.today'), count: props.counts?.today ?? 0 },
    { value: 'upcoming', label: __('leadhub::followups.sections.upcoming'), count: props.counts?.upcoming ?? 0 },
]);

const bucketColor = (key) => ({ overdue: 'red', today: 'amber', upcoming: 'default' }[key] ?? 'default');

const toDelete = ref(null);

function complete(row) {
    router.post(row.complete_url, {}, { preserveScroll: true });
}

function destroy() {
    if (! toDelete.value) return;
    router.delete(toDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { toDelete.value = null; },
    });
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Follow-ups'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Follow-ups')" icon="calendar" />

        <EmptyStateMenu
            v-if="isEmpty && !hasFormConnected"
            :heading="__('No follow-ups yet — connect a form first.')"
        >
            <EmptyStateItem
                :href="configureFormsUrl"
                icon="forms"
                :heading="__('Configure forms')"
                :description="__('Once contacts come in, you can schedule follow-ups from each contact page.')"
            />
        </EmptyStateMenu>

        <div v-else-if="isEmpty" class="text-center py-16">
            <Icon name="checkmark" class="size-12 text-gray-400 mx-auto mb-4" />
            <p class="text-gray-500">{{ __('All caught up — no follow-ups due.') }}</p>
        </div>

        <template v-else>
            <div class="mb-4 flex flex-wrap items-center gap-2" data-leadhub-followup-buckets>
                <Button
                    v-for="b in buckets"
                    :key="String(b.value)"
                    size="sm"
                    :variant="bucket === b.value ? 'pressed' : 'default'"
                    :text="`${b.label} (${b.count})`"
                    @click="bucket = b.value"
                />
            </div>

            <Listing
                :items="visible"
                :columns="columns"
                preferences-prefix="leadhub.followups"
                @refreshing="reloadPage"
            >
                <template #cell-contact_name="{ row }">
                    <a v-if="row.contact_url" :href="row.contact_url" class="font-medium hover:underline">{{ row.contact_name }}</a>
                    <span v-else class="font-medium">{{ row.contact_name }}</span>
                </template>

                <template #cell-due_at="{ row }">
                    <span :class="row.bucket === 'overdue' ? 'text-red-600 font-medium' : 'text-sm'">
                        {{ row.due_at || '—' }}
                    </span>
                </template>

                <template #cell-bucket_label="{ row }">
                    <Badge pill :color="bucketColor(row.bucket)" :text="row.bucket_label" />
                </template>

                <template #cell-note="{ row }">
                    <span class="text-sm text-gray-500">{{ row.note || '—' }}</span>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('Mark as done')" icon="checkmark" @click="complete(row)" />
                    <DropdownItem
                        :text="__('Remove')"
                        icon="trash"
                        variant="destructive"
                        @click="toDelete = row"
                    />
                </template>
            </Listing>
        </template>

        <ConfirmationModal
            :open="toDelete !== null"
            :title="__('Remove follow-up')"
            :body-text="__('Remove this follow-up? The contact keeps its history.')"
            danger
            :button-text="__('Remove')"
            @cancel="toDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
