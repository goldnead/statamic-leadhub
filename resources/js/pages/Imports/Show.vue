<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Alert,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';

const props = defineProps([
    'batch',       // summary + { issues: [{ row, type, reason, cells }], issues_total, errors_url }
    'backUrl',     // string
    'contactsUrl', // string
    'refreshUrl',  // string
]);

let timer = null;

// The queue works while the page is open: ask for the numbers again until the
// import says it is finished. Only the `batch` prop is reloaded.
onMounted(() => {
    if (props.batch.finished) return;

    timer = setInterval(() => {
        if (props.batch.finished) {
            clearInterval(timer);
            return;
        }
        router.reload({ only: ['batch'] });
    }, 2000);
});

onBeforeUnmount(() => timer && clearInterval(timer));

const color = computed(() => ({ completed: 'green', failed: 'red', running: 'blue', queued: 'amber' }[props.batch.status] || 'default'));

function downloadReport() {
    window.location.href = props.batch.errors_url;
}
</script>

<template>
    <Head :title="[batch.filename, __('Import contacts'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="batch.filename" icon="users">
            <Button :text="__('All imports')" variant="default" @click="router.visit(backUrl)" />
            <Button :text="__('Open contacts')" variant="primary" @click="router.visit(contactsUrl)" />
        </Header>

        <Alert v-if="batch.status === 'failed'" variant="error" class="mb-4" :text="batch.message || __('The import stopped. Rows before the failure were imported.')" />

        <Panel :heading="__('Progress')">
            <Card>
                <div class="flex items-center gap-3 mb-4">
                    <Badge pill :color="color" :text="batch.status_label" />
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __(':done of :total rows', { done: batch.processed, total: batch.total_rows }) }}
                        ({{ batch.percent }}%)
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <div class="text-2xl font-medium">{{ batch.counts.created }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Created') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-medium">{{ batch.counts.updated }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Updated') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-medium">{{ batch.counts.skipped }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Skipped') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-medium">{{ batch.counts.errors }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Errors') }}</div>
                    </div>
                </div>
            </Card>
        </Panel>

        <Panel
            v-if="batch.issues.length"
            :heading="__('Skipped and invalid rows')"
            :subheading="__('Showing :shown of :total', { shown: batch.issues.length, total: batch.issues_total })"
            class="mt-6"
        >
            <template #header-actions>
                <Button :text="__('Download report')" icon="download" variant="default" @click="downloadReport" />
            </template>
            <Table>
                <TableColumns>
                    <TableColumn>{{ __('Row') }}</TableColumn>
                    <TableColumn>{{ __('Reason') }}</TableColumn>
                    <TableColumn>{{ __('Content') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="issue in batch.issues" :key="issue.row">
                        <TableCell>{{ issue.row }}</TableCell>
                        <TableCell>
                            <Badge pill :color="issue.type === 'error' ? 'red' : 'amber'" :text="issue.reason" />
                        </TableCell>
                        <TableCell>{{ issue.cells }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>
    </div>
</template>
