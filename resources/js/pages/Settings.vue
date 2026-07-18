<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Panel, Alert, Badge } from '@statamic/cms/ui';

defineProps([
    'config',           // raw config('leadhub') array
    'driver',           // active driver
    'publishCommand',   // 'php artisan vendor:publish --tag=leadhub-config'
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
                <div class="p-4 text-sm">
                    <Badge :color="driver === 'flat' ? 'amber' : 'blue'" :text="driver" />
                    <span class="ml-2 text-gray-700 dark:text-gray-300">
                        {{ driver === 'flat'
                            ? __('YAML files under content/leadhub/')
                            : __('Database tables (leadhub_*)') }}
                    </span>
                </div>
            </Panel>

            <Panel :heading="__('Lead statuses')">
                <ul class="divide-y divide-content-border text-sm">
                    <li v-for="(label, key) in config.statuses" :key="key" class="px-4 py-2 flex justify-between">
                        <span class="text-gray-500">{{ key }}</span>
                        <span>{{ label }}</span>
                    </li>
                </ul>
            </Panel>

            <Panel :heading="__('Behavior')">
                <dl class="p-4 text-sm space-y-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">overwrite_existing_fields_from_submissions</dt>
                        <dd>{{ config.overwrite_existing_fields_from_submissions ? 'true' : 'false' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">store_full_submission_payload</dt>
                        <dd>{{ config.store_full_submission_payload ? 'true' : 'false' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">default_status</dt>
                        <dd>{{ config.default_status }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">exports.queue_threshold</dt>
                        <dd>{{ config.exports?.queue_threshold ?? 1000 }}</dd>
                    </div>
                </dl>
            </Panel>

            <Panel :heading="__('Payload redaction')">
                <div class="p-4 flex flex-wrap gap-2">
                    <Badge
                        v-for="key in config.timeline_payload_redaction"
                        :key="key"
                        color="default"
                        :text="key"
                    />
                </div>
            </Panel>

            <Panel :heading="__('Feature flags')">
                <dl class="p-4 text-sm space-y-1">
                    <div v-for="(value, key) in config.features" :key="key" class="flex justify-between">
                        <dt class="text-gray-500">{{ key }}</dt>
                        <dd>{{ value ? '✓' : '—' }}</dd>
                    </div>
                </dl>
            </Panel>
        </div>
    </div>
</template>
