<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge, Button, DropdownItem } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps(['companies', 'columns', 'filters', 'canManage', 'createUrl']);

const errors = ref({});

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function destroy(row) {
    // A refused deletion answers with a 422 and an error bag. Without the
    // onError branch the row would simply stay and the click would look
    // ignored, which is exactly the failure mode this release is closing.
    router.delete(row.delete_url, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
    });
}
</script>

<template>
    <Head :title="[__('Companies'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Companies')" icon="office-building">
            <template #actions>
                <Button
                    v-if="createUrl"
                    :text="__('New company')"
                    icon="add"
                    variant="primary"
                    data-leadhub-new-company
                    @click="router.visit(createUrl)"
                />
            </template>
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Organizations linked to your contacts.') }}
        </p>

        <ErrorSummary :errors="errors" />

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
                <Badge color="default" :text="String(row.contacts_count)" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('Edit')"
                    icon="edit"
                    @click="router.visit(row.edit_url)"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('Delete')"
                    icon="trash"
                    variant="destructive"
                    @click="destroy(row)"
                />
            </template>
        </Listing>
    </div>
</template>
