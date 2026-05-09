<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Icon, EmptyStateMenu, EmptyStateItem, DropdownItem,
} from '@statamic/cms/ui';

const props = defineProps([
    'forms',          // [{ handle, title, enabled, email_field, last_processed_at, edit_url }]
    'columns',        // Array<Column>
]);

const isEmpty = computed(() => props.forms.length === 0);

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Form Mappings'), __('LeadHub')]" />

    <div v-if="isEmpty" class="max-w-page mx-auto">
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="forms" class="size-5 text-gray-500" />
                {{ __('Form Mappings') }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Create a Statamic form first, then come back here to map it.')">
            <EmptyStateItem
                href="/cp/forms"
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
                    :color="row.enabled ? 'green' : 'gray'"
                    :text="row.enabled ? __('Active') : __('Disabled')"
                />
            </template>

            <template #cell-email_field="{ row }">
                <span v-if="row.email_field" class="text-xs text-gray-700 dark:text-gray-300">
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
