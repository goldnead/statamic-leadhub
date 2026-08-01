<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Badge, Field, Input, Select, Text, ConfirmationModal } from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';

const props = defineProps([
    'opportunity',
    'stages',           // [{ value, label }] — of this opportunity's pipeline
    'companyOptions',
    'assignableUsers',
    'tasks',            // the tasks hanging on this deal — every one, done included
    'tasksEnabled',
    'canManageTasks',
    'createTaskUrl',
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

const taskList = computed(() => props.tasks || []);

function completeTask(task) {
    router.post(task.complete_url, {}, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
    });
}

const confirmingDelete = ref(false);

function destroy() {
    router.delete(props.deleteUrl, {
        preserveScroll: true,
        // "This opportunity still has N tasks" comes back as an error bag.
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { confirmingDelete.value = false; },
    });
}
</script>

<template>
    <Head :title="[__('Edit opportunity'), opportunity.title, __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('Edit opportunity')" icon="chart-pie">
            <Button :text="__('Delete')" icon="trash" variant="danger" data-leadhub-delete-opportunity @click="confirmingDelete = true" />
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

        <!--
            The tasks on this deal. Until v1.9.0 the link ran one way only: a
            task could name an opportunity, the opportunity could not name its
            tasks — and the delete refusal ("this opportunity still has 3
            tasks") pointed at records the screen never showed. Everything
            here is listed, completed tasks included, because that is what the
            refusal counts.
        -->
        <div v-if="tasksEnabled" class="mt-8" data-leadhub-opportunity-tasks>
            <Panel :heading="`${__('leadhub::tasks.title')} (${taskList.length})`">
                <Card>
                    <div class="p-1">
                        <Text v-if="!taskList.length" size="sm" variant="subtle" data-leadhub-opportunity-tasks-empty>
                            {{ __('leadhub::pipelines.opportunity_tasks_empty') }}
                        </Text>

                        <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                            <li
                                v-for="task in taskList"
                                :key="task.id"
                                class="flex flex-wrap items-center gap-2 py-2"
                                :data-leadhub-opportunity-task="task.id"
                            >
                                <a
                                    :href="task.edit_url"
                                    class="font-medium hover:underline"
                                    :class="task.is_open ? '' : 'line-through text-gray-500 dark:text-gray-400'"
                                >{{ task.title }}</a>

                                <Badge v-if="task.priority === 'high'" color="orange" :text="task.priority_label" />
                                <Badge v-if="!task.is_open" color="green" :text="__('leadhub::tasks.filters.done')" />
                                <Badge v-if="task.is_overdue" color="red" :text="__('leadhub::tasks.filters.overdue')" />

                                <Text size="sm" variant="subtle" class="ms-auto">
                                    <span v-if="task.due_at">{{ task.due_at }}</span>
                                    <span v-if="task.assignee_name"> · {{ task.assignee_name }}</span>
                                    <span v-else> · {{ __('leadhub::tasks.unassigned') }}</span>
                                </Text>

                                <Button
                                    v-if="task.is_open"
                                    :text="__('Mark complete')"
                                    size="sm"
                                    variant="default"
                                    :data-leadhub-opportunity-complete-task="task.id"
                                    @click="completeTask(task)"
                                />
                            </li>
                        </ul>

                        <div v-if="canManageTasks && createTaskUrl" class="mt-4">
                            <Button
                                :text="__('leadhub::tasks.new')"
                                icon="plus"
                                size="sm"
                                data-leadhub-opportunity-new-task
                                @click="router.visit(createTaskUrl)"
                            />
                        </div>
                    </div>
                </Card>
            </Panel>

            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">
                {{ __('leadhub::pipelines.opportunity_tasks_hint') }}
            </p>
        </div>

        <ConfirmationModal
            :open="confirmingDelete"
            :title="__('Delete opportunity')"
            :body-text="__('Delete this opportunity? An opportunity that still has open tasks is refused.')"
            danger
            :button-text="__('Delete')"
            @cancel="confirmingDelete = false"
            @confirm="destroy"
        />
    </div>
</template>
