<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Badge, Field, Input, Select, Text } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps([
    'opportunity',
    'stages',           // [{ value, label }] — of this opportunity's pipeline
    'companyOptions',
    'assignableUsers',
    'updateUrl',
    'deleteUrl',
    'cancelUrl',
]);

const form = ref({
    stage_id: props.opportunity.stage_id || '',
    company_id: props.opportunity.company_id || '',
    title: props.opportunity.title || '',
    value_estimate: props.opportunity.value_estimate ?? '',
    confidence: props.opportunity.confidence ?? 0,
    owner_id: props.opportunity.owner_id || '',
});

const errors = ref({});
const processing = ref(false);

const companySelectOptions = computed(() => [
    { value: '', label: __('No company') },
    ...(props.companyOptions || []),
]);

const ownerOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

const canSubmit = computed(() => !processing.value);

function submit() {
    if (processing.value) return;

    processing.value = true;
    errors.value = {};

    router.patch(props.updateUrl, {
        ...form.value,
        company_id: form.value.company_id || null,
        owner_id: form.value.owner_id || null,
        value_estimate: form.value.value_estimate === '' ? null : form.value.value_estimate,
    }, {
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { processing.value = false; },
    });
}

function destroy() {
    router.delete(props.deleteUrl, {
        preserveScroll: true,
        // "This opportunity still has N tasks" comes back as an error bag.
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
    });
}
</script>

<template>
    <Head :title="[__('Edit opportunity'), opportunity.title, __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('Edit opportunity')" icon="chart-pie">
            <template #actions>
                <Button :text="__('Delete')" icon="trash" variant="danger" data-leadhub-delete-opportunity @click="destroy" />
            </template>
        </Header>

        <ErrorSummary
            :errors="errors"
            :fields="['stage_id', 'company_id', 'title', 'value_estimate', 'confidence', 'owner_id']"
        />

        <form @submit.prevent="submit">
            <Panel>
                <Card>
                    <div class="grid gap-4 sm:grid-cols-2 p-1">
                        <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                            <Badge color="default" :text="opportunity.pipeline_name" />
                            <Badge v-if="opportunity.outcome" :color="opportunity.outcome === 'won' ? 'green' : 'red'" :text="opportunity.outcome" />
                            <Text v-if="opportunity.contact_name" size="sm" variant="subtle">{{ opportunity.contact_name }}</Text>
                        </div>

                        <Field :label="__('Title')" :error="errors.title" class="sm:col-span-2">
                            <Input v-model="form.title" data-leadhub-opportunity-title />
                        </Field>
                        <Field :label="__('Stage')" :error="errors.stage_id">
                            <Select v-model="form.stage_id" :options="stages" data-leadhub-opportunity-stage />
                        </Field>
                        <Field :label="__('Owner')" :error="errors.owner_id">
                            <Select v-model="form.owner_id" :options="ownerOptions" />
                        </Field>
                        <Field :label="__('Value')" :error="errors.value_estimate">
                            <Input v-model="form.value_estimate" type="number" step="0.01" min="0" />
                        </Field>
                        <Field :label="__('Confidence')" :error="errors.confidence">
                            <Input v-model="form.confidence" type="number" min="0" max="100" />
                        </Field>
                        <Field v-if="companyOptions.length" :label="__('Company')" :error="errors.company_id" class="sm:col-span-2">
                            <Select v-model="form.company_id" :options="companySelectOptions" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">
                {{ __('Changing the stage records a transition, exactly as moving the card on the board does.') }}
            </p>

            <div class="flex items-center gap-2 mt-4">
                <Button
                    type="submit"
                    :text="__('Save changes')"
                    variant="primary"
                    :disabled="!canSubmit"
                    data-leadhub-opportunity-submit
                />
                <Button :text="__('Cancel')" variant="default" @click="router.visit(cancelUrl)" />
            </div>
        </form>
    </div>
</template>
