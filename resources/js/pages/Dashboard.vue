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
    'leadsByStatus',        // [{ key, label, count, filter_url, is_default }]
    'hasFormConnected',     // boolean
    'configureFormsUrl',    // string
    'contactsUrl',          // string
    'followupsUrl',         // string
    // Deployment-owned, read-only. [{ label, value, env }], empty without the
    // `manage leadhub settings` permission. Came from the settings screen when
    // that moved to the suite's shared one in brand-context — env values are not
    // settings, so they do not belong on a screen generated from a field list.
    'environment',
    'environmentTexts',     // { heading, description, publishCommand }
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
                            <!-- The handle, not the label: it is what is stored
                                 on every contact, and an operator editing
                                 leadhub.statuses needs to see which strings are
                                 actually in use. -->
                            <div class="mt-1 flex items-center gap-1.5">
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ bucket.key }}</code>
                                <Badge v-if="bucket.is_default" pill color="blue" :text="__('Default')" />
                            </div>
                        </Card>
                    </Link>
                </div>
            </Panel>
        </template>

        <!-- Owned by the deployment: shown, never offered. -->
        <Panel v-if="environment && environment.length" :heading="environmentTexts.heading" class="mt-6">
            <Card>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ environmentTexts.description }}
                    <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-800">{{ environmentTexts.publishCommand }}</code>
                </p>

                <div
                    v-for="entry in environment"
                    :key="entry.env + entry.label"
                    class="flex items-start justify-between gap-4 border-t border-gray-200 py-3 first:border-t-0 dark:border-gray-700"
                    :data-leadhub-environment="entry.env"
                >
                    <div class="min-w-0">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ entry.label }}</span>
                        <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ entry.env }}</span>
                    </div>
                    <span class="shrink-0 text-right text-sm text-gray-900 dark:text-gray-300">{{ entry.value }}</span>
                </div>
            </Card>
        </Panel>
    </div>
</template>
