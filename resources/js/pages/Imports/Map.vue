<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Field, Input, Select, Checkbox, Badge,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';

const props = defineProps([
    'batch',         // { id, filename, total_rows, header: [string] }
    'suggested',     // [string] — guessed target per column, '' = leave out
    'targets',       // [{ value, label }]
    'statuses',      // [{ value, label }]
    'defaultStatus', // string
    'disclaimer',    // [string]
    'preview',       // null | { columns: [{ key, label }], rows: [{ row, cells, outcome, reason }], total }
    'previewUrl',    // string
    'runUrl',        // string
    'cancelUrl',     // string
]);

const mapping = ref([...props.suggested]);
const tag = ref('');
const status = ref(props.defaultStatus || '');
const consentGiven = ref(false);
const errors = ref({});
const processing = ref(false);

const targetOptions = computed(() => [
    { value: '', label: __('Leave this column out') },
    ...props.targets,
]);

const hasEmail = computed(() => mapping.value.includes('email'));

function payload() {
    return {
        mapping: mapping.value,
        tag: tag.value,
        status: status.value,
        consent_given: consentGiven.value,
    };
}

function send(url, extra = {}) {
    if (processing.value) return;

    processing.value = true;
    errors.value = {};

    router.post(url, payload(), {
        preserveState: true,
        preserveScroll: true,
        onError: (e) => { errors.value = e; },
        onFinish: () => { processing.value = false; },
        ...extra,
    });
}

const showPreview = () => send(props.previewUrl, { only: ['preview'] });
const run = () => send(props.runUrl);

function outcomeColor(outcome) {
    return { create: 'green', update: 'blue', skipped: 'amber', invalid: 'red' }[outcome] || 'default';
}

function outcomeLabel(outcome) {
    return {
        create: __('Will be created'),
        update: __('Will be merged'),
        skipped: __('Skipped'),
        invalid: __('Invalid'),
    }[outcome] || outcome;
}
</script>

<template>
    <Head :title="[__('Map columns'), __('Import contacts'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Map columns')" icon="users" />

        <Panel :heading="__('Before you import')">
            <Card>
                <p v-for="line in disclaimer" :key="line" class="text-sm text-gray-700 dark:text-gray-300 mb-2 last:mb-0">
                    {{ line }}
                </p>
            </Card>
        </Panel>

        <Panel
            :heading="__('Match each column to a contact field')"
            :subheading="__(':file, :count rows', { file: props.batch.filename, count: props.batch.total_rows })"
            class="mt-6"
        >
            <Card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field
                        v-for="(name, index) in props.batch.header"
                        :key="index"
                        :label="name || __('Column :n', { n: index + 1 })"
                    >
                        <Select v-model="mapping[index]" :options="targetOptions" />
                    </Field>
                </div>
                <p v-if="errors.mapping" class="text-sm text-red-600 mt-3">{{ errors.mapping }}</p>
                <p v-else-if="!hasEmail" class="text-sm text-gray-600 dark:text-gray-400 mt-3">
                    {{ __('Choose the column that holds the email address.') }}
                </p>
            </Card>
        </Panel>

        <Panel :heading="__('For all imported contacts')" class="mt-6">
            <Card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="__('Add a tag')" :instructions="__('Optional. Created if it does not exist yet.')" :error="errors.tag">
                        <Input v-model="tag" :placeholder="__('e.g. Import October')" />
                    </Field>
                    <Field :label="__('Status for new contacts')" :error="errors.status">
                        <Select v-model="status" :options="statuses" />
                    </Field>
                    <div class="sm:col-span-2">
                        <Checkbox v-model="consentGiven" :label="__('Everyone in this file gave consent to marketing emails')" />
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                            {{ __('Leave this off if you do not know. Contacts then come in without a recorded consent, and a consent column in the file is still honoured. LeadHub never fills this in for you.') }}
                        </p>
                    </div>
                </div>
            </Card>
        </Panel>

        <div class="flex items-center gap-2 mt-4">
            <Button :text="__('Preview')" variant="default" :disabled="!hasEmail || processing" @click="showPreview" />
            <Button
                :text="__('Import :count contacts', { count: props.batch.total_rows })"
                variant="primary"
                :disabled="!hasEmail || processing"
                @click="run"
            />
            <Button :text="__('Cancel')" variant="ghost" @click="router.visit(cancelUrl)" />
        </div>

        <Panel v-if="preview" :heading="__('Preview of the first rows')" class="mt-8">
            <Table>
                <TableColumns>
                    <TableColumn>{{ __('Row') }}</TableColumn>
                    <TableColumn v-for="column in preview.columns" :key="column.key">{{ column.label }}</TableColumn>
                    <TableColumn>{{ __('Result') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="row in preview.rows" :key="row.row">
                        <TableCell>{{ row.row }}</TableCell>
                        <TableCell v-for="column in preview.columns" :key="column.key">{{ row.cells[column.key] }}</TableCell>
                        <TableCell>
                            <Badge pill :color="outcomeColor(row.outcome)" :text="outcomeLabel(row.outcome)" />
                            <span v-if="row.reason" class="ml-2 text-xs text-gray-600 dark:text-gray-400">{{ row.reason }}</span>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>
    </div>
</template>
