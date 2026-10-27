<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Textarea, Badge, Heading,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';

const props = defineProps([
    'disclaimer',   // [string] — plain-language notice shown before anything is chosen
    'uploadUrl',    // string — POST target
    'cancelUrl',    // string
    'maxKb',        // int
    'history',      // [{ id, filename, status, status_label, finished, counts, total_rows, created_at, url }]
]);

const mode = ref('file');
const file = ref(null);
const text = ref('');
const errors = ref({});
const processing = ref(false);

const ready = computed(() => mode.value === 'file' ? !!file.value : text.value.trim() !== '');

function pick(event) {
    file.value = event.target.files?.[0] || null;
}

function submit() {
    if (! ready.value || processing.value) return;

    processing.value = true;
    errors.value = {};

    const data = mode.value === 'file' ? { file: file.value } : { text: text.value };

    router.post(props.uploadUrl, data, {
        forceFormData: mode.value === 'file',
        onError: (e) => { errors.value = e; },
        onFinish: () => { processing.value = false; },
    });
}

function statusColor(status) {
    return { completed: 'green', failed: 'red', running: 'blue', queued: 'amber' }[status] || 'default';
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString() : '';
}
</script>

<template>
    <Head :title="[__('Import contacts'), __('Contacts'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Import contacts')" icon="users">
            <Button :text="__('Cancel')" variant="default" @click="router.visit(cancelUrl)" />
        </Header>

        <Panel :heading="__('Before you import')">
            <Card>
                <p v-for="line in disclaimer" :key="line" class="text-sm text-gray-700 dark:text-gray-300 mb-2 last:mb-0">
                    {{ line }}
                </p>
            </Card>
        </Panel>

        <Panel :heading="__('Add contacts')" class="mt-6">
            <Card>
                <div class="flex items-center gap-2 mb-4">
                    <Button
                        :text="__('Upload a CSV file')"
                        icon="upload"
                        :variant="mode === 'file' ? 'primary' : 'default'"
                        @click="mode = 'file'"
                    />
                    <Button
                        :text="__('Paste from Excel')"
                        icon="clipboard"
                        :variant="mode === 'paste' ? 'primary' : 'default'"
                        @click="mode = 'paste'"
                    />
                </div>

                <div v-if="mode === 'file'">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                        {{ __('A CSV or TXT file with a header row. Semicolon, comma and tab separated files all work.') }}
                    </p>
                    <input
                        type="file"
                        accept=".csv,.txt,.tsv,text/csv,text/plain"
                        class="block w-full text-sm text-gray-700 dark:text-gray-300"
                        @change="pick"
                    >
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        {{ __('Up to :mb MB.', { mb: Math.floor(maxKb / 1024) }) }}
                    </p>
                    <p v-if="errors.file" class="text-sm text-red-600 mt-2">{{ errors.file }}</p>
                </div>

                <div v-else>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                        {{ __('Copy the cells from your spreadsheet, including the header row, and paste them here.') }}
                    </p>
                    <Textarea v-model="text" rows="8" :placeholder="__('Paste your cells here')" />
                    <p v-if="errors.text" class="text-sm text-red-600 mt-2">{{ errors.text }}</p>
                </div>
            </Card>
        </Panel>

        <div class="flex items-center gap-2 mt-4">
            <Button
                :text="__('Continue')"
                variant="primary"
                :disabled="!ready || processing"
                @click="submit"
            />
        </div>

        <Panel :heading="__('Previous imports')" class="mt-8">
            <Card v-if="!history.length">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No imports yet.') }}</p>
            </Card>
            <Table v-else>
                <TableColumns>
                    <TableColumn>{{ __('File') }}</TableColumn>
                    <TableColumn>{{ __('Status') }}</TableColumn>
                    <TableColumn>{{ __('Row count') }}</TableColumn>
                    <TableColumn>{{ __('Created') }}</TableColumn>
                    <TableColumn>{{ __('Updated') }}</TableColumn>
                    <TableColumn>{{ __('Skipped') }}</TableColumn>
                    <TableColumn>{{ __('Errors') }}</TableColumn>
                    <TableColumn>{{ __('Date') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="item in history" :key="item.id">
                        <TableCell>
                            <Link :href="item.url" class="font-medium hover:underline">{{ item.filename }}</Link>
                        </TableCell>
                        <TableCell><Badge pill :color="statusColor(item.status)" :text="item.status_label" /></TableCell>
                        <TableCell>{{ item.total_rows }}</TableCell>
                        <TableCell>{{ item.counts.created }}</TableCell>
                        <TableCell>{{ item.counts.updated }}</TableCell>
                        <TableCell>{{ item.counts.skipped }}</TableCell>
                        <TableCell>{{ item.counts.errors }}</TableCell>
                        <TableCell>{{ when(item.created_at) }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>
    </div>
</template>
