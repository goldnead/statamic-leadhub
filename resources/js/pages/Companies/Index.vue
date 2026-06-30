<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge } from '@statamic/cms/ui';

defineProps(['companies', 'columns', 'filters']);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function open(row) {
    router.visit(row.url);
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
            :items="companies.data"
            :columns="columns"
            :meta="companies"
            preferences-prefix="leadhub.companies"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <button class="font-medium text-left hover:text-blue-600" @click="open(row)">
                    {{ row.name }}
                </button>
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
