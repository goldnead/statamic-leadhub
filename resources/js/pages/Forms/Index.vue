<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, EmptyStateMenu, EmptyStateItem, DropdownItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'forms',            // [{ handle, title, enabled, email_field, last_processed_at, edit_url }]
    'columns',          // Array<Column>
    'statamicFormsUrl', // cp_route('forms.index') — the CP prefix is configurable
]);

const isEmpty = computed(() => props.forms.length === 0);

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Form Mappings'), __('LeadHub')]" />

    <div v-if="isEmpty" class="max-w-page mx-auto">
        <Header :title="__('Form Mappings')" icon="forms" />
        <EmptyStateMenu :heading="__('Create a Statamic form first, then come back here to map it.')">
            <EmptyStateItem
                :href="statamicFormsUrl"
                icon="forms"
                :heading="__('Open Statamic Forms')"
                :description="__('Make at least one Statamic form. LeadHub will detect it automatically.')"
            />
        </EmptyStateMenu>
    </div>

    <div v-else class="max-w-page mx-auto">
        <Header :title="__('Form Mappings')" icon="forms">
            <template #title>
                <span>{{ __('Form Mappings') }}</span>
            </template>
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Toggle which Statamic forms create LeadHub contacts and how their fields map.') }}
        </p>

        <Listing
            :items="forms"
            :columns="columns"
            preferences-prefix="leadhub.forms"
            @refreshing="reloadPage"
        >
            <template #cell-title="{ row }">
                <Link :href="row.edit_url" class="font-medium hover:underline">{{ row.title }}</Link>
                <div class="text-xs text-gray-500 mt-0.5">{{ row.handle }}</div>
            </template>

            <template #cell-enabled="{ row }">
                <Badge
                    :color="row.enabled ? 'green' : 'default'"
                    :text="row.enabled ? __('Active') : __('Disabled')"
                />
            </template>

            <template #cell-email_field="{ row }">
                <span v-if="row.email_field" class="text-xs text-gray-900 dark:text-gray-300">
                    ✓ {{ row.email_field }}
                </span>
                <span v-else class="text-xs text-gray-400">—</span>
            </template>

            <template #cell-last_processed_at="{ row }">
                <span class="text-xs text-gray-500">
                    {{ row.last_processed_at || __('Never') }}
                </span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem :text="__('Configure')" :href="row.edit_url" icon="cog" />
            </template>
        </Listing>
    </div>
</template>
