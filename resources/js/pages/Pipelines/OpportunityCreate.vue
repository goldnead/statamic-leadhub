<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Field, Input, Select } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';
import ContactPicker from '../../support/ContactPicker.vue';

const props = defineProps([
    'opportunity',      // prefilled defaults
    'pipelines',        // [{ value, label, stages: [{ value, label }] }]
    'companyOptions',
    'contactOptions',
    'contactSearchUrl',
    'assignableUsers',
    'storeUrl',
    'cancelUrl',
]);

const form = ref({
    contact_id: props.opportunity.contact_id || '',
    company_id: props.opportunity.company_id || '',
    pipeline_id: props.opportunity.pipeline_id || '',
    stage_id: props.opportunity.stage_id || '',
    title: props.opportunity.title || '',
    value_estimate: props.opportunity.value_estimate || '',
    confidence: props.opportunity.confidence ?? 0,
    owner_id: props.opportunity.owner_id || '',
});

const errors = ref({});
const processing = ref(false);

const pipelineOptions = computed(() =>
    (props.pipelines || []).map((p) => ({ value: p.value, label: p.label }))
);

const stageOptions = computed(() => {
    const pipeline = (props.pipelines || []).find((p) => String(p.value) === String(form.value.pipeline_id));
    return pipeline ? pipeline.stages : [];
});

const companySelectOptions = computed(() => [
    { value: '', label: __('No company') },
    ...(props.companyOptions || []),
]);

const ownerOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

// Switching pipeline invalidates the stage; keeping the old id would post a
// stage belonging to a different pipeline, which the request refuses.
watch(() => form.value.pipeline_id, () => {
    const first = stageOptions.value[0];
    form.value.stage_id = first ? first.value : '';
});

const canSubmit = computed(() => !!form.value.contact_id && !!form.value.pipeline_id);

function submit() {
    if (!canSubmit.value || processing.value) return;

    processing.value = true;
    errors.value = {};

    router.post(props.storeUrl, {
        ...form.value,
        company_id: form.value.company_id || null,
        owner_id: form.value.owner_id || null,
        value_estimate: form.value.value_estimate === '' ? null : form.value.value_estimate,
    }, {
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <Head :title="[__('New opportunity'), __('Pipelines'), __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('New opportunity')" icon="chart-pie" />

        <ErrorSummary
            :errors="errors"
            :fields="['contact_id', 'company_id', 'pipeline_id', 'stage_id', 'title', 'value_estimate', 'confidence', 'owner_id']"
        />

        <form @submit.prevent="submit">
            <Panel>
                <Card>
                    <div class="grid gap-4 sm:grid-cols-2 p-1">
                        <Field :label="__('Contact')" :error="errors.contact_id" class="sm:col-span-2">
                            <ContactPicker
                                v-model="form.contact_id"
                                :options="contactOptions"
                                :search-url="contactSearchUrl"
                            />
                        </Field>
                        <Field :label="__('Pipeline')" :error="errors.pipeline_id">
                            <Select v-model="form.pipeline_id" :options="pipelineOptions" />
                        </Field>
                        <Field :label="__('Stage')" :error="errors.stage_id">
                            <Select v-model="form.stage_id" :options="stageOptions" data-leadhub-opportunity-stage />
                        </Field>
                        <Field :label="__('Title')" :error="errors.title" class="sm:col-span-2">
                            <Input v-model="form.title" :placeholder="__('Left empty: contact name and pipeline')" data-leadhub-opportunity-title />
                        </Field>
                        <Field :label="__('Value')" :error="errors.value_estimate">
                            <Input v-model="form.value_estimate" type="number" step="0.01" min="0" />
                        </Field>
                        <Field :label="__('Confidence')" :error="errors.confidence">
                            <Input v-model="form.confidence" type="number" min="0" max="100" />
                        </Field>
                        <Field v-if="companyOptions.length" :label="__('Company')" :error="errors.company_id">
                            <Select v-model="form.company_id" :options="companySelectOptions" />
                        </Field>
                        <Field :label="__('Owner')" :error="errors.owner_id">
                            <Select v-model="form.owner_id" :options="ownerOptions" />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <div class="flex items-center gap-2 mt-4">
                <Button
                    type="submit"
                    :text="__('Create opportunity')"
                    variant="primary"
                    :disabled="!canSubmit || processing"
                    data-leadhub-opportunity-submit
                />
                <Button :text="__('Cancel')" variant="default" @click="router.visit(cancelUrl)" />
            </div>
        </form>
    </div>
</template>
