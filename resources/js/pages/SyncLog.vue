<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Panel, Card, Badge, Text } from '@statamic/cms/ui';

defineProps([
    'enabled', // boolean — features.crm_destinations
    'logs',    // [{ id, contact_label, contact_url, destination, driver, event, status, response_code, message, created_at }]
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
        <Panel v-else-if="logs.length === 0" :heading="__('leadhub::crm.title')">
            <Card>
                <Text variant="subtle">{{ __('leadhub::crm.empty') }}</Text>
            </Card>
        </Panel>

        <!-- Log table -->
        <Panel v-else :heading="__('leadhub::crm.title')">
            <Card>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-2xs uppercase tracking-wide text-gray-500 border-b border-content-border">
                            <th class="py-2 pr-3 font-medium">{{ __('leadhub::crm.contact') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('leadhub::crm.destination') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('leadhub::crm.event') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('leadhub::crm.status') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('leadhub::crm.detail') }}</th>
                            <th class="py-2 font-medium text-right">{{ __('leadhub::crm.time') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-content-border">
                        <tr v-for="log in logs" :key="log.id">
                            <td class="py-2.5 pr-3">
                                <Link v-if="log.contact_url" :href="log.contact_url" class="font-medium hover:underline">
                                    {{ log.contact_label }}
                                </Link>
                                <span v-else>{{ log.contact_label }}</span>
                            </td>
                            <td class="py-2.5 pr-3">
                                {{ log.destination }}
                                <Text size="xs" variant="subtle">{{ log.driver }}</Text>
                            </td>
                            <td class="py-2.5 pr-3 text-gray-500">{{ log.event }}</td>
                            <td class="py-2.5 pr-3">
                                <Badge
                                    :color="log.status === 'success' ? 'green' : 'red'"
                                    :text="log.status"
                                />
                            </td>
                            <td class="py-2.5 pr-3 text-gray-500 max-w-xs truncate" :title="log.message">
                                <span v-if="log.response_code" class="text-2xs text-gray-400">{{ log.response_code }}</span>
                                {{ log.message }}
                            </td>
                            <td class="py-2.5 text-right text-xs text-gray-500 whitespace-nowrap">{{ log.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </Panel>
    </div>
</template>
