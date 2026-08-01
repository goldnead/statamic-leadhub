<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Panel, Card, Badge, Text, Listing } from '@statamic/cms/ui';

defineProps([
    'enabled',  // boolean — features.crm_destinations
    'columns',  // Array<Column>
    'dataUrl',  // the listing's server-mode endpoint
    'hasLogs',  // whether anything has ever been logged
]);
</script>

<template>
    <Head :title="[__('leadhub::crm.title'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('leadhub::crm.title')" icon="loading" />

        <!-- Feature disabled -->
        <Panel v-if="!enabled" :heading="__('leadhub::crm.disabled_heading')">
            <Card>
                <Text variant="subtle">{{ __('leadhub::crm.disabled_description') }}</Text>
            </Card>
        </Panel>

        <!-- Enabled but no syncs yet -->
        <Panel v-else-if="!hasLogs" :heading="__('leadhub::crm.title')">
            <Card>
                <Text variant="subtle">{{ __('leadhub::crm.empty') }}</Text>
            </Card>
        </Panel>

        <!--
            Server mode. The log grows without bound, so it is paginated rather
            than truncated at a fixed row count the way the old hand-built
            table was.
        -->
        <Listing
            v-else
            :url="dataUrl"
            :columns="columns"
            :allow-bulk-actions="false"
            preferences-prefix="leadhub.sync_log"
            sort-column="created_at"
            sort-direction="desc"
        >
            <template #cell-contact_label="{ row }">
                <Link v-if="row.contact_url" :href="row.contact_url" class="font-medium hover:underline">
                    {{ row.contact_label }}
                </Link>
                <span v-else>{{ row.contact_label }}</span>
            </template>

            <template #cell-destination="{ row }">
                {{ row.destination }}
                <Text size="xs" variant="subtle">{{ row.driver }}</Text>
            </template>

            <template #cell-event="{ row }">
                <span class="text-gray-500">{{ row.event }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge
                    :color="row.status === 'success' ? 'green' : 'red'"
                    :text="row.status_label || row.status"
                />
            </template>

            <template #cell-message="{ row }">
                <span class="text-gray-500 max-w-xs truncate inline-block align-bottom" :title="row.message">
                    <span v-if="row.response_code" class="text-2xs text-gray-400">{{ row.response_code }}</span>
                    {{ row.message }}
                </span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="text-xs text-gray-500 whitespace-nowrap">{{ row.created_at }}</span>
            </template>
        </Listing>
    </div>
</template>
