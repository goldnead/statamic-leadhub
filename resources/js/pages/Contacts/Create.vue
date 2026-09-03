<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Field, Input, Select, Checkbox,
} from '@statamic/cms/ui';

const props = defineProps([
    'statuses',         // { key: label }
    'defaultStatus',    // string
    'assignableUsers',  // [{ value, label }]
    'tagOptions',       // [{ value, label }]
    'storeUrl',         // string — POST target
    'cancelUrl',        // string
]);

const form = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    status: props.defaultStatus || '',
    assigned_to: '',
    consent: false,
    tag_ids: [],
});

const errors = ref({});
const processing = ref(false);

const statusOptions = computed(() =>
    Object.entries(props.statuses || {}).map(([value, label]) => ({ value, label }))
);

const ownerOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

const canSubmit = computed(() =>
    !!(form.value.email.trim() || form.value.phone.trim())
);

function submit() {
    if (! canSubmit.value || processing.value) return;

    processing.value = true;
    errors.value = {};

    router.post(props.storeUrl, {
        ...form.value,
        assigned_to: form.value.assigned_to || null,
    }, {
        onError: (e) => { errors.value = e; },
        onFinish: () => { processing.value = false; },
    });
}

function cancel() {
    router.visit(props.cancelUrl);
}
</script>

<template>
    <Head :title="[__('New contact'), __('Contacts'), __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('New contact')" icon="users" />

        <form @submit.prevent="submit">
            <Panel>
                <Card>
                    <div class="grid gap-4 sm:grid-cols-2 p-1">
                        <Field :label="__('First name')" :error="errors.first_name">
                            <Input v-model="form.first_name" :placeholder="__('e.g. Jane')" />
                        </Field>
                        <Field :label="__('Last name')" :error="errors.last_name">
                            <Input v-model="form.last_name" :placeholder="__('e.g. Doe')" />
                        </Field>
                        <Field :label="__('Email')" :error="errors.email">
                            <Input v-model="form.email" type="email" :placeholder="__('jane@example.com')" />
                        </Field>
                        <Field :label="__('Phone')" :error="errors.phone">
                            <Input v-model="form.phone" :placeholder="__('+49 …')" />
                        </Field>
                        <Field :label="__('Company')" :error="errors.company" class="sm:col-span-2">
                            <Input v-model="form.company" />
                        </Field>
                        <Field :label="__('Status')" :error="errors.status">
                            <Select v-model="form.status" :options="statusOptions" />
                        </Field>
                        <Field :label="__('Owner')" :error="errors.assigned_to">
                            <Select v-model="form.assigned_to" :options="ownerOptions" />
                        </Field>
                        <div class="sm:col-span-2">
                            <Checkbox v-model="form.consent" :label="__('Marketing consent given')" />
                        </div>
                    </div>
                </Card>
            </Panel>

            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">
                {{ __('Enter at least an email address or a phone number.') }}
            </p>

            <div class="flex items-center gap-2 mt-4">
                <Button
                    type="submit"
                    :text="__('Create contact')"
                    variant="primary"
                    :disabled="!canSubmit || processing"
                />
                <Button :text="__('Cancel')" variant="default" @click="cancel" />
            </div>
        </form>
    </div>
</template>
