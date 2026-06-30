<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Badge, Text, Icon, EmptyStateMenu, EmptyStateItem } from '@statamic/cms/ui';

const props = defineProps([
    'overdue',          // [{ id, contact_name, contact_url, due_at, note, complete_url, delete_url }]
    'today',            // same shape
    'upcoming',         // same shape
    'configureFormsUrl',
    'hasFormConnected',
]);

const isEmpty = computed(() =>
    props.overdue.length === 0 &&
    props.today.length === 0 &&
    props.upcoming.length === 0,
);

function complete(f) {
    router.post(f.complete_url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Follow-ups'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Follow-ups')" icon="calendar" />

        <EmptyStateMenu
            v-if="isEmpty && !hasFormConnected"
            :heading="__('No follow-ups yet — connect a form first.')"
        >
            <EmptyStateItem
                :href="configureFormsUrl"
                icon="forms"
                :heading="__('Configure forms')"
                :description="__('Once contacts come in, you can schedule follow-ups from each contact page.')"
            />
        </EmptyStateMenu>

        <div v-else-if="isEmpty" class="text-center py-16">
            <Icon name="check-circle" class="size-12 text-gray-400 mx-auto mb-4" />
            <p class="text-gray-500">{{ __('All caught up — no follow-ups due.') }}</p>
        </div>

        <div v-else class="space-y-6">
            <!-- Overdue -->
            <Panel v-if="overdue.length > 0" :heading="`${__('Overdue')} (${overdue.length})`">
                <Card>
                    <ul class="-my-3 divide-y divide-content-border">
                        <li v-for="f in overdue" :key="f.id" class="py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <Link :href="f.contact_url" class="font-medium hover:underline">{{ f.contact_name }}</Link>
                                <div class="mt-1">
                                    <Badge color="red" :text="f.due_at" />
                                </div>
                                <Text v-if="f.note" size="sm" variant="subtle" class="mt-1 block">{{ f.note }}</Text>
                            </div>
                            <Button :text="__('Mark as done')" size="sm" variant="default" @click="complete(f)" />
                        </li>
                    </ul>
                </Card>
            </Panel>

            <!-- Today -->
            <Panel :heading="`${__('Due today')} (${today.length})`">
                <Card>
                    <div v-if="today.length === 0" class="py-6 text-sm text-gray-500 text-center">
                        {{ __('No follow-ups due today.') }}
                    </div>
                    <ul v-else class="-my-3 divide-y divide-content-border">
                        <li v-for="f in today" :key="f.id" class="py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <Link :href="f.contact_url" class="font-medium hover:underline">{{ f.contact_name }}</Link>
                                <Text size="xs" variant="subtle" class="mt-0.5 block">{{ f.due_at }}</Text>
                                <Text v-if="f.note" size="sm" variant="subtle" class="mt-1 block">{{ f.note }}</Text>
                            </div>
                            <Button :text="__('Mark as done')" size="sm" variant="default" @click="complete(f)" />
                        </li>
                    </ul>
                </Card>
            </Panel>

            <!-- Upcoming -->
            <Panel :heading="`${__('Upcoming')} (${upcoming.length})`">
                <Card>
                    <div v-if="upcoming.length === 0" class="py-6 text-sm text-gray-500 text-center">
                        {{ __('No upcoming follow-ups.') }}
                    </div>
                    <ul v-else class="-my-3 divide-y divide-content-border">
                        <li v-for="f in upcoming" :key="f.id" class="py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <Link :href="f.contact_url" class="font-medium hover:underline">{{ f.contact_name }}</Link>
                                <Text size="xs" variant="subtle" class="mt-0.5 block">{{ f.due_at }}</Text>
                                <Text v-if="f.note" size="sm" variant="subtle" class="mt-1 block">{{ f.note }}</Text>
                            </div>
                        </li>
                    </ul>
                </Card>
            </Panel>
        </div>
    </div>
</template>
