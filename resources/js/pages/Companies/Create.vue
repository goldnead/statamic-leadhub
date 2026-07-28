<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Field, Input, Textarea, Select } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps([
    'assignableUsers',  // [{ value, label }]
    'storeUrl',
    'cancelUrl',
]);

const form = ref({
    name: '',
    website: '',
    industry: '',
    employee_range: '',
    description: '',
    owner_id: '',
});

const errors = ref({});
const processing = ref(false);

const ownerOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

const canSubmit = computed(() => !!form.value.name.trim());

function submit() {
    if (!canSubmit.value || processing.value) return;

    processing.value = true;
    errors.value = {};

    router.post(props.storeUrl, { ...form.value, owner_id: form.value.owner_id || null }, {
        // Without this branch a rejected company looks like a dead button —
        // the single most common defect the QA run found across these addons.
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <Head :title="[__('New company'), __('Companies'), __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('New company')" icon="office-building" />

        <ErrorSummary :errors="errors" :fields="['name', 'website', 'industry', 'employee_range', 'description', 'owner_id']" />

        <form @submit.prevent="submit">
            <Panel>
                <Card>
                    <div class="grid gap-4 sm:grid-cols-2 p-1">
                        <Field :label="__('Name')" :error="errors.name" class="sm:col-span-2">
                            <Input v-model="form.name" :placeholder="__('e.g. Muster GmbH')" data-leadhub-company-name />
                        </Field>
                        <Field :label="__('Website')" :error="errors.website">
                            <Input v-model="form.website" :placeholder="__('example.com')" />
                        </Field>
                        <Field :label="__('Industry')" :error="errors.industry">
                            <Input v-model="form.industry" />
                        </Field>
                        <Field :label="__('Employees')" :error="errors.employee_range">
                            <Input v-model="form.employee_range" :placeholder="__('e.g. 11-50')" />
                        </Field>
                        <Field :label="__('Owner')" :error="errors.owner_id">
                            <Select v-model="form.owner_id" :options="ownerOptions" />
                        </Field>
                        <Field :label="__('Description')" :error="errors.description" class="sm:col-span-2">
                            <Textarea v-model="form.description" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <div class="flex items-center gap-2 mt-4">
                <Button
                    type="submit"
                    :text="__('Create company')"
                    variant="primary"
                    :disabled="!canSubmit || processing"
                    data-leadhub-company-submit
                />
                <Button :text="__('Cancel')" variant="default" @click="router.visit(cancelUrl)" />
            </div>
        </form>
    </div>
</template>
