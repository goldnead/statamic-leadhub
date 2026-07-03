<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Description } from '@statamic/cms/ui';

defineProps(['company', 'contacts']);

function openContact(url) {
    if (url) router.visit(url);
}
</script>

<template>
    <Head :title="[company.name, __('Companies'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="company.name" icon="office-building" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <Panel class="lg:col-span-1">
                <div class="p-4 space-y-3">
                    <div>
                        <div class="text-xs text-gray-500 uppercase">{{ __('Website') }}</div>
                        <a v-if="company.website" :href="company.website" target="_blank" class="text-blue-600 text-sm">
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
    </div>
</template>
