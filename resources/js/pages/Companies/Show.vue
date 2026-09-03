<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Badge, Button, Description, Listing,
    Dropdown, DropdownMenu, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps(['company', 'contacts', 'contactColumns', 'canManage']);

const errors = ref({});
const confirmingDelete = ref(false);

function destroy() {
    router.delete(props.company.delete_url, {
        preserveScroll: true,
        // The refusal ("this company still has N contacts") arrives as an
        // error bag on a 422. It has to land on the screen, otherwise the
        // delete button is indistinguishable from a broken one.
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { confirmingDelete.value = false; },
    });
}
</script>

<template>
    <Head :title="[company.name, __('Companies'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="company.name" icon="building-generic">
            <!-- Core order: the "…" menu first, the primary action last, and
                 `danger` only inside a confirmation modal. -->
            <template v-if="canManage">
                <Dropdown>
                    <DropdownMenu>
                        <DropdownItem
                            :text="__('Delete')"
                            icon="trash"
                            variant="destructive"
                            data-leadhub-delete-company
                            @click="confirmingDelete = true"
                        />
                    </DropdownMenu>
                </Dropdown>
                <Button :text="__('Edit')" icon="edit" variant="primary" data-leadhub-edit-company @click="router.visit(company.edit_url)" />
            </template>
        </Header>

        <ErrorSummary :errors="errors" />

        <!--
            The single-column grid utility is deliberately absent: a grid falls
            back to one column on its own, and every sibling addon shipping a
            Tailwind build emits that same bare, breakpoint-less rule into the
            shared `addon-utilities` layer. Media queries add no specificity, so
            whichever addon stylesheet loads last wins over an earlier `lg:`
            variant and flattens that addon's grid to one column at every width.
            Leaving the class off means no foreign rule can match this element.

            Do not name the class in a comment either — Tailwind scans comments
            as candidates, so writing it here is enough to emit the very rule
            this avoids.

            `*:min-w-0` on the container keeps what the utility's
            `minmax(0,1fr)` track provided: the implicit column is `auto`, which
            the unbroken website URL below would push past the container. On the
            container rather than each child, so it cannot be forgotten when a
            child is added.
        -->
        <div class="grid lg:grid-cols-3 gap-4 *:min-w-0">
            <Panel class="lg:col-span-1" :heading="__('Details')">
                <Card class="space-y-3">
                    <div>
                        <div class="text-xs text-gray-500 uppercase">{{ __('Website') }}</div>
                        <a v-if="company.website" :href="company.website" target="_blank" class="text-primary text-sm">
                            {{ company.domain || company.website }}
                        </a>
                        <span v-else class="text-sm text-gray-400">—</span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">{{ __('Industry') }}</div>
                        <div class="text-sm">{{ company.industry || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">{{ __('Employees') }}</div>
                        <div class="text-sm">{{ company.employee_range || '—' }}</div>
                    </div>
                    <div v-if="company.description">
                        <div class="text-xs text-gray-500 uppercase">{{ __('Description') }}</div>
                        <p class="text-sm">{{ company.description }}</p>
                    </div>
                </Card>
            </Panel>

            <!--
                The linked contacts are a list of records, so they get the same
                `Listing` the contacts screen uses. As cards they had no
                sorting, no search and no column picker, and looked like
                nothing else in the CP.
            -->
            <div class="lg:col-span-2">
                <Description v-if="!contacts.length" class="px-2">{{ __('No contacts linked yet.') }}</Description>
                <Listing
                    v-else
                    :items="contacts"
                    :columns="contactColumns"
                    preferences-prefix="leadhub.company-contacts"
                    data-leadhub-company-contacts
                >
                    <template #cell-name="{ row }">
                        <div class="flex items-center gap-2">
                            <a :href="row.url" class="font-medium hover:underline">{{ row.name }}</a>
                            <Badge v-if="row.is_primary" color="blue" size="sm" pill :text="__('Primary')" />
                        </div>
                    </template>

                    <template #cell-status_label="{ row }">
                        <Badge pill :text="row.status_label" />
                    </template>

                    <template #cell-relationship_label="{ row }">
                        <span class="text-sm text-gray-500">{{ row.relationship_label || '—' }}</span>
                    </template>

                    <template #prepended-row-actions="{ row }">
                        <DropdownItem :text="__('Open contact')" :href="row.url" icon="external-link" />
                    </template>
                </Listing>
            </div>
        </div>

        <ConfirmationModal
            :open="confirmingDelete"
            :title="__('Delete company')"
            :body-text="__('Delete this company? Its contacts stay, only the company record and the links to it go.')"
            danger
            :button-text="__('Delete')"
            @cancel="confirmingDelete = false"
            @confirm="destroy"
        />
    </div>
</template>
