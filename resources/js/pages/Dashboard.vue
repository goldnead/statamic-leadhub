<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, EmptyStateMenu, EmptyStateItem,
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

function statusColor(key) {
    return {
        new: 'gray',
        contacted: 'blue',
        qualified: 'amber',
        won: 'green',
        lost: 'red',
        archived: 'gray',
    }[key] || 'gray';
}
</script>

<template>
    <Head :title="[__('LeadHub'), __('Dashboard')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('LeadHub')" icon="list">
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

        <!-- KPI cards -->
        <div v-else class="grid gap-4 md:grid-cols-4 mb-6">
            <Link
                :href="`${contactsUrl}?from=${kpis.new_leads_week_from}`"
                class="block p-4 rounded-md border border-content-border bg-content-bg hover:shadow transition"
            >
                <div class="text-2xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('New leads (7 days)') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ kpis.new_leads_week }}</div>
            </Link>

            <Link
                :href="`${contactsUrl}?status=qualified`"
                class="block p-4 rounded-md border border-content-border bg-content-bg hover:shadow transition"
            >
                <div class="text-2xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Qualified leads') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ kpis.qualified }}</div>
            </Link>

            <Link
                :href="`${contactsUrl}?status=won`"
                class="block p-4 rounded-md border border-content-border bg-content-bg hover:shadow transition"
            >
                <div class="text-2xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Won leads') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ kpis.won }}</div>
            </Link>

            <Link
                :href="followupsUrl"
                class="block p-4 rounded-md border border-content-border bg-content-bg hover:shadow transition"
            >
                <div class="text-2xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Due follow-ups') }}
                </div>
                <div class="text-3xl font-bold mt-1">
                    {{ kpis.due_followups }}
                    <span v-if="kpis.overdue_followups > 0" class="text-sm font-normal text-red-600 ml-2">
                        +{{ kpis.overdue_followups }} {{ __('overdue') }}
                    </span>
                </div>
            </Link>
        </div>

        <!-- Two-column layout -->
        <div v-if="!isEmpty" class="grid gap-6 md:grid-cols-2">
            <Panel :heading="__('Latest activity')">
                <div v-if="latestActivity.length === 0" class="px-4 py-6 text-sm text-gray-500 text-center">
                    {{ __('No activity yet.') }}
                </div>
                <ul v-else class="divide-y divide-content-border">
                    <li v-for="event in latestActivity" :key="event.id" class="px-4 py-3 text-sm">
                        <div class="flex items-center justify-between">
                            <Link :href="event.contact_url" class="font-medium hover:underline">
                                {{ event.contact_name }}
                            </Link>
                            <span class="text-xs text-gray-500">{{ event.created_at }}</span>
                        </div>
                        <div class="text-gray-700 dark:text-gray-300">{{ event.summary }}</div>
                    </li>
                </ul>
            </Panel>

            <div class="space-y-4">
                <Panel :heading="__('Follow-ups due today')">
                    <div v-if="followupsToday.length === 0" class="px-4 py-6 text-sm text-gray-500 text-center">
                        {{ __('No follow-ups due today.') }}
                    </div>
                    <ul v-else class="divide-y divide-content-border">
                        <li v-for="f in followupsToday" :key="f.id" class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <Link :href="f.contact_url" class="font-medium hover:underline">
                                    {{ f.contact_name }}
                                </Link>
                                <span class="text-xs text-gray-500">{{ f.due_at }}</span>
                            </div>
                            <div v-if="f.note" class="text-gray-700 dark:text-gray-300">{{ f.note }}</div>
                        </li>
                    </ul>
                </Panel>

                <Panel v-if="followupsOverdue.length > 0" :heading="__('Overdue follow-ups')">
                    <ul class="divide-y divide-content-border">
                        <li v-for="f in followupsOverdue" :key="f.id" class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <Link :href="f.contact_url" class="font-medium hover:underline">
                                    {{ f.contact_name }}
                                </Link>
                                <Badge color="red" :text="f.due_at" />
                            </div>
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>

        <!-- Leads by status -->
        <Panel v-if="!isEmpty" :heading="__('Leads by status')" class="mt-6">
            <div class="grid grid-cols-2 md:grid-cols-6 gap-2 p-4">
                <Link
                    v-for="bucket in leadsByStatus"
                    :key="bucket.key"
                    :href="bucket.filter_url"
                    class="block rounded-md border border-content-border px-3 py-2 hover:shadow transition"
                >
                    <Badge :color="statusColor(bucket.key)" :text="bucket.label" />
                    <div class="text-xl font-semibold mt-1">{{ bucket.count }}</div>
                </Link>
            </div>
        </Panel>
    </div>
</template>
