<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Button, Field, Input, Textarea, Select, DatePicker } from '@statamic/cms/ui';
import { toDateTimeString } from '../../support/datetime';
import ErrorSummary from '../../support/ErrorSummary.vue';
import ContactPicker from '../../support/ContactPicker.vue';

const props = defineProps([
    'task',
    'assignableUsers',
    'priorityOptions',
    'contactOptions',
    'contactSearchUrl',
    'updateUrl',
    'cancelUrl',
]);

const form = ref({
    title: props.task.title || '',
    description: props.task.description || '',
    contact_id: props.task.contact_id || '',
    priority: props.task.priority || 'normal',
    assignee_id: props.task.assignee_id || '',
    // The picker parses a plain string fine; it is the value coming *back* out
    // of it that needs normalizing.
    due_at: props.task.due_at || null,
});

const errors = ref({});
const processing = ref(false);

const assigneeOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

const canSubmit = computed(() => !!form.value.title.trim());

function submit() {
    if (!canSubmit.value || processing.value) return;

    processing.value = true;
    errors.value = {};

    router.patch(props.updateUrl, {
        ...form.value,
        contact_id: form.value.contact_id || null,
        assignee_id: form.value.assignee_id || null,
        due_at: toDateTimeString(form.value.due_at),
    }, {
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <Head :title="[__('Edit task'), task.title, __('LeadHub')]" />

    <div class="max-w-2xl mx-auto">
        <Header :title="__('Edit task')" icon="tasks" />

        <ErrorSummary :errors="errors" :fields="['title', 'description', 'contact_id', 'priority', 'assignee_id', 'due_at']" />

        <form @submit.prevent="submit">
            <Panel>
                <Card>
                    <div class="grid gap-4 sm:grid-cols-2 p-1">
                        <Field :label="__('Title')" :error="errors.title" class="sm:col-span-2">
                            <Input v-model="form.title" data-leadhub-task-title />
                        </Field>
                        <Field :label="__('Contact')" :error="errors.contact_id" class="sm:col-span-2">
                            <ContactPicker
                                v-model="form.contact_id"
                                :options="contactOptions"
                                :search-url="contactSearchUrl"
                            />
                        </Field>
                        <Field :label="__('Assignee')" :error="errors.assignee_id">
                            <Select v-model="form.assignee_id" :options="assigneeOptions" data-leadhub-task-assignee />
                        </Field>
                        <Field :label="__('Priority')" :error="errors.priority">
                            <Select v-model="form.priority" :options="priorityOptions" />
                        </Field>
                        <Field :label="__('Due')" :error="errors.due_at" class="sm:col-span-2">
                            <DatePicker v-model="form.due_at" granularity="minute" clearable />
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
                    :text="__('Save changes')"
                    variant="primary"
                    :disabled="!canSubmit || processing"
                    data-leadhub-task-submit
                />
                <Button :text="__('Cancel')" variant="default" @click="router.visit(cancelUrl)" />
            </div>
        </form>
    </div>
</template>
