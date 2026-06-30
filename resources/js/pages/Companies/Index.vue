<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge } from '@statamic/cms/ui';

defineProps(['companies', 'columns', 'filters']);

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Companies'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Companies')" icon="office-building" />

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Organizations linked to your contacts.') }}
        </p>

        <Listing
            :items="companies"
            :columns="columns"
            preferences-prefix="leadhub.companies"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.url" class="font-medium hover:underline">{{ row.name }}</Link>
            </template>

            <template #cell-domain="{ row }">
                <span class="text-xs text-gray-500">{{ row.domain || '—' }}</span>
            </template>

            <template #cell-industry="{ row }">
                <span class="text-sm">{{ row.industry || '—' }}</span>
            </template>

            <template #cell-contacts_count="{ row }">
                <Badge color="gray" :text="String(row.contacts_count)" />
            </template>
        </Listing>
    </div>
</template>
