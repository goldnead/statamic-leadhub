<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button, Description, ConfirmationModal } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps(['company', 'contacts', 'canManage']);

const errors = ref({});
const confirmingDelete = ref(false);

function openContact(url) {
    if (url) router.visit(url);
}

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
        <Header :title="company.name" icon="office-building">
            <div v-if="canManage" class="flex items-center gap-2">
                <Button :text="__('Edit')" icon="edit" variant="default" data-leadhub-edit-company @click="router.visit(company.edit_url)" />
                <Button :text="__('Delete')" icon="trash" variant="danger" data-leadhub-delete-company @click="confirmingDelete = true" />
            </div>
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
            <Panel class="lg:col-span-1">
                <div class="p-4 space-y-3">
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
                </div>
            </Panel>

            <Panel class="lg:col-span-2" :heading="__('Contacts')">
                <div class="p-2">
                    <Description v-if="!contacts.length">{{ __('No contacts linked yet.') }}</Description>
                    <ul v-else class="divide-y divide-content-border">
                        <li
                            v-for="contact in contacts"
                            :key="contact.id"
                            class="flex items-center justify-between py-2 px-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800"
                            @click="openContact(contact.url)"
                        >
                            <div>
                                <div class="font-medium text-sm">{{ contact.name }}</div>
                                <div class="text-xs text-gray-500">{{ contact.email }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge v-if="contact.relationship_label" color="default" :text="contact.relationship_label" />
                                <Badge v-if="contact.is_primary" color="blue" :text="__('Primary')" />
                            </div>
                        </li>
                    </ul>
                </div>
            </Panel>
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
