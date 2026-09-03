<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Listing, Badge, Button, DropdownItem, ConfirmationModal, CommandPaletteItem } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps(['companies', 'columns', 'filters', 'canManage', 'createUrl']);

const errors = ref({});
const companyToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function confirmDelete(row) {
    companyToDelete.value = row;
}

function destroy() {
    if (! companyToDelete.value) return;

    // A refused deletion answers with a 422 and an error bag. Without the
    // onError branch the row would simply stay and the click would look
    // ignored, which is exactly the failure mode this release is closing.
    router.delete(companyToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { companyToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Companies'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Companies')" icon="building-generic">
            <CommandPaletteItem
                v-if="createUrl"
                category="Actions"
                :text="__('New company')"
                icon="building-generic"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button
                    :text="text"
                    icon="plus"
                    variant="primary"
                    data-leadhub-new-company
                    @click="router.visit(url)"
                />
            </CommandPaletteItem>
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
                    @click="confirmDelete(row)"
                />
            </template>
        </Listing>

        <ConfirmationModal
            :open="companyToDelete !== null"
            :title="__('Delete company')"
            :body-text="__('Delete this company? Its contacts stay, only the company record and the links to it go.')"
            danger
            :button-text="__('Delete')"
            @cancel="companyToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
