<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Panel, Card, Alert, Badge } from '@statamic/cms/ui';

const props = defineProps([
    // Allow-listed slice of config('leadhub'): statuses, default_status, the
    // two behaviour booleans, timeline_payload_redaction, features and
    // exports.queue_threshold. Never the whole file — see SettingsController.
    'config',
    'driver',           // active driver
    'publishCommand',   // 'php artisan vendor:publish --tag=leadhub-config'
]);

// Human-readable rows for the Behavior panel. The raw config key is kept as a
// mono sub-label so nothing is lost, while the label gives it a proper name.
const behaviorRows = computed(() => [
    {
        key: 'overwrite_existing_fields_from_submissions',
        label: __('Overwrite existing fields from submissions'),
        value: props.config.overwrite_existing_fields_from_submissions,
        type: 'bool',
    },
    {
        key: 'store_full_submission_payload',
        label: __('Store full submission payload'),
        value: props.config.store_full_submission_payload,
        type: 'bool',
    },
    {
        key: 'default_status',
        label: __('Default lead status'),
        value: props.config.default_status,
        type: 'text',
    },
    {
        key: 'exports.queue_threshold',
        label: __('Export queue threshold'),
        value: props.config.exports?.queue_threshold ?? 1000,
        type: 'text',
    },
]);
</script>

<template>
    <Head :title="[__('Settings'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Settings')" icon="cog" />

        <Alert variant="default" class="mb-4">
            {{ __('LeadHub settings are read-only here. Edit') }}
            <code class="px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs">config/leadhub.php</code>
            {{ __('to change them. Run') }}
            <code class="px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs">{{ publishCommand }}</code>
            {{ __('once to publish the config to your project.') }}
        </Alert>

        <div class="space-y-4">
            <Panel :heading="__('Storage driver')">
                <Card>
                    <div class="flex items-center gap-3 p-1 text-sm">
                        <Badge :color="driver === 'flat' ? 'amber' : 'blue'" :text="driver" />
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ driver === 'flat'
                                ? __('YAML files under content/leadhub/')
                                : __('Database tables (leadhub_*)') }}
                        </span>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Lead statuses')">
                <Card>
                    <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="(label, key) in config.statuses"
                            :key="key"
                            class="flex items-center justify-between gap-4 py-3 first:pt-1 last:pb-1"
                        >
                            <dt class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ label }}</dt>
                            <dd>
                                <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono text-gray-600 dark:text-gray-300">{{ key }}</code>
                            </dd>
                        </div>
                    </dl>
                </Card>
            </Panel>

            <Panel :heading="__('Behavior')">
                <Card>
                    <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="row in behaviorRows"
                            :key="row.key"
                            class="flex items-start justify-between gap-4 py-3 first:pt-1 last:pb-1"
                        >
                            <div class="min-w-0">
                                <dt class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ row.label }}</dt>
                                <dd class="mt-0.5 text-xs font-mono text-gray-500 dark:text-gray-400">{{ row.key }}</dd>
                            </div>
                            <div class="shrink-0 text-sm">
                                <Badge
                                    v-if="row.type === 'bool'"
                                    :color="row.value ? 'green' : 'default'"
                                    :text="row.value ? __('Enabled') : __('Disabled')"
                                />
                                <span v-else class="font-mono text-gray-700 dark:text-gray-300">{{ row.value }}</span>
                            </div>
                        </div>
                    </dl>
                </Card>
            </Panel>

            <Panel :heading="__('Payload redaction')">
                <Card>
                    <div class="flex flex-wrap gap-2 p-1">
                        <Badge
                            v-for="key in config.timeline_payload_redaction"
                            :key="key"
                            color="default"
                            :text="key"
                        />
                        <span
                            v-if="!config.timeline_payload_redaction || !config.timeline_payload_redaction.length"
                            class="text-sm text-gray-500 dark:text-gray-400 italic"
                        >
                            {{ __('No payload keys are redacted.') }}
                        </span>
                    </div>
                </Card>
            </Panel>

            <Panel :heading="__('Feature flags')">
                <Card>
                    <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="(value, key) in config.features"
                            :key="key"
                            class="flex items-center justify-between gap-4 py-3 first:pt-1 last:pb-1"
                        >
                            <dt class="text-sm font-mono text-gray-700 dark:text-gray-300">{{ key }}</dt>
                            <dd>
                                <Badge :color="value ? 'green' : 'default'" :text="value ? __('On') : __('Off')" />
                            </dd>
                        </div>
                    </dl>
                </Card>
            </Panel>
        </div>
    </div>
</template>
