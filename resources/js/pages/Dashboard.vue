<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, Card, Heading, Subheading, Text,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'kpis',                 // { new_leads_week, qualified, won, due_followups, overdue_followups, new_leads_week_from }
    'latestActivity',       // [{ id, contact_name, contact_url, summary, type, created_at }]
    'followupsToday',       // [{ id, contact_name, contact_url, due_at, note }]
    'followupsOverdue',     // [{ id, contact_name, contact_url, due_at, note }]
    'leadsByStatus',        // [{ key, label, count, filter_url }]
    'hasFormConnected',     // boolean
    'configureFormsUrl',    // string
    'contactsUrl',          // string
    'followupsUrl',         // string
]);

const isEmpty = computed(() => ! props.hasFormConnected);

const kpiTiles = computed(() => [
    {
        label: __('New leads (7 days)'),
        value: props.kpis.new_leads_week,
        href: `${props.contactsUrl}?from=${props.kpis.new_leads_week_from}`,
    },
    {
        label: __('Qualified leads'),
        value: props.kpis.qualified,
        href: `${props.contactsUrl}?status=qualified`,
    },
    {
        label: __('Won leads'),
        value: props.kpis.won,
        href: `${props.contactsUrl}?status=won`,
    },
    {
        label: __('Due follow-ups'),
        value: props.kpis.due_followups,
        overdue: props.kpis.overdue_followups,
        href: props.followupsUrl,
    },
]);

function statusColor(key) {
    return {
        new: 'default',
        contacted: 'blue',
        qualified: 'amber',
        won: 'green',
        lost: 'red',
        archived: 'default',
    }[key] || 'default';
}
</script>

<template>
    <Head :title="[__('LeadHub'), __('Dashboard')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('LeadHub')" icon="dashboard">
            <Button
                v-if="hasFormConnected"
                :href="configureFormsUrl"
                :text="__('Configure forms')"
                variant="default"
            />
        </Header>

        <!-- Empty state -->
        <EmptyStateMenu
            v-if="isEmpty"
            :heading="__('Connect your first Statamic form to start turning submissions into contacts.')"
        >
            <EmptyStateItem
                :href="configureFormsUrl"
                icon="forms"
                :heading="__('Configure forms')"
                :description="__('Pick a Statamic form and map its fields to a LeadHub contact.')"
            />
        </EmptyStateMenu>

        <template v-else>
            <!-- KPI cards -->
            <div class="grid gap-4 md:grid-cols-4 mb-6">
                <Link
                    v-for="tile in kpiTiles"
                    :key="tile.label"
                    :href="tile.href"
                    class="group block focus:outline-none"
                >
                    <Card class="h-full transition group-hover:border-gray-300 dark:group-hover:border-gray-600">
                        <Subheading :text="tile.label" />
                        <div class="mt-2 flex items-baseline gap-2">
                            <Heading size="2xl" :text="String(tile.value)" />
                            <Text v-if="tile.overdue > 0" size="sm" variant="danger">
                                +{{ tile.overdue }} {{ __('Overdue') }}
                            </Text>
                        </div>
                    </Card>
                </Link>
            </div>

            <!-- Two-column layout -->
            <div class="grid gap-6 md:grid-cols-2">
                <Panel :heading="__('Latest activity')">
                    <Card>
                        <div v-if="latestActivity.length === 0" class="py-6 text-sm text-gray-500 text-center">
                            {{ __('No activity yet.') }}
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="event in latestActivity" :key="event.id" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between">
                                    <Link :href="event.contact_url" class="font-medium hover:underline">
                                        {{ event.contact_name }}
                                    </Link>
                                    <Text size="xs" variant="subtle">{{ event.created_at }}</Text>
                                </div>
                                <Text size="sm" variant="subtle">{{ event.summary }}</Text>
                            </li>
                        </ul>
                    </Card>
                </Panel>

                <div class="space-y-6">
                    <Panel :heading="__('Follow-ups due today')">
                        <Card>
                            <div v-if="followupsToday.length === 0" class="py-6 text-sm text-gray-500 text-center">
                                {{ __('No follow-ups due today.') }}
                            </div>
                            <ul v-else class="-my-2 divide-y divide-content-border">
                                <li v-for="f in followupsToday" :key="f.id" class="py-2.5 text-sm">
                                    <div class="flex items-center justify-between">
                                        <Link :href="f.contact_url" class="font-medium hover:underline">
                                            {{ f.contact_name }}
                                        </Link>
                                        <Text size="xs" variant="subtle">{{ f.due_at }}</Text>
                                    </div>
                                    <Text v-if="f.note" size="sm" variant="subtle">{{ f.note }}</Text>
                                </li>
                            </ul>
                        </Card>
                    </Panel>

                    <Panel v-if="followupsOverdue.length > 0" :heading="__('Overdue follow-ups')">
                        <Card>
                            <ul class="-my-2 divide-y divide-content-border">
                                <li v-for="f in followupsOverdue" :key="f.id" class="py-2.5 text-sm">
                                    <div class="flex items-center justify-between">
                                        <Link :href="f.contact_url" class="font-medium hover:underline">
                                            {{ f.contact_name }}
                                        </Link>
                                        <Badge color="red" :text="f.due_at" />
                                    </div>
                                </li>
                            </ul>
                        </Card>
                    </Panel>
                </div>
            </div>

            <!-- Leads by status -->
            <Panel :heading="__('Leads by status')" class="mt-6">
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 p-4">
                    <Link
                        v-for="bucket in leadsByStatus"
                        :key="bucket.key"
                        :href="bucket.filter_url"
                        class="group block focus:outline-none"
                    >
                        <Card class="h-full transition group-hover:border-gray-300 dark:group-hover:border-gray-600">
                            <Badge :color="statusColor(bucket.key)" :text="bucket.label" />
                            <Heading size="lg" class="mt-2" :text="String(bucket.count)" />
                        </Card>
                    </Link>
                </div>
            </Panel>
        </template>
    </div>
</template>
