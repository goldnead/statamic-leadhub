<script setup>
import { ref, reactive, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button, Field, Input, Select, Switch, Text, Icon, ConfirmationModal } from '@statamic/cms/ui';

const props = defineProps(['pipelines', 'storeUrl', 'canManage']);

const name = ref('');
const stages = ref([
    { name: 'New', is_terminal: false, terminal_outcome: null },
    { name: 'Won', is_terminal: true, terminal_outcome: 'won' },
    { name: 'Lost', is_terminal: true, terminal_outcome: 'lost' },
]);

const outcomeOptions = [
    { value: null, label: '—' },
    { value: 'won', label: __('Won') },
    { value: 'lost', label: __('Lost') },
];

function addStage() {
    stages.value.push({ name: '', is_terminal: false, terminal_outcome: null });
}

function removeStage(index) {
    stages.value.splice(index, 1);
}

function create() {
    if (!name.value.trim() || !stages.value.length) return;
    router.post(props.storeUrl, { name: name.value, stages: stages.value }, {
        preserveScroll: true,
        onSuccess: () => { name.value = ''; },
    });
}

// --- Editing the stages of an existing pipeline ---------------------------
//
// "Add stage" used to only append, and nothing could be renamed or moved
// afterwards. A stage that landed in the wrong place could only be fixed by
// rebuilding the whole pipeline. Order is edited locally and saved in one
// request, so a half-applied reorder can't happen.

const errors = ref({});
const draft = reactive({});   // pipeline id -> ordered stage list (local copy)
const newStage = reactive({}); // pipeline id -> { name, is_terminal, terminal_outcome }

function syncDrafts(pipelines) {
    (pipelines || []).forEach((pipeline) => {
        draft[pipeline.id] = pipeline.stages.map((stage) => ({ ...stage }));
        newStage[pipeline.id] ??= { name: '', is_terminal: false, terminal_outcome: null };
    });
}

syncDrafts(props.pipelines);
watch(() => props.pipelines, (value) => syncDrafts(value), { deep: true });

function orderChanged(pipeline) {
    const local = (draft[pipeline.id] || []).map((s) => s.id).join(',');
    const server = pipeline.stages.map((s) => s.id).join(',');
    return local !== server;
}

function move(pipeline, index, delta) {
    const list = draft[pipeline.id];
    const target = index + delta;
    if (!list || target < 0 || target >= list.length) return;
    const [item] = list.splice(index, 1);
    list.splice(target, 0, item);
}

function saveOrder(pipeline) {
    router.post(pipeline.reorder_url, {
        stage_ids: (draft[pipeline.id] || []).map((s) => s.id),
    }, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
    });
}

function saveStage(stage) {
    router.patch(stage.update_url, {
        name: stage.name,
        is_terminal: !!stage.is_terminal,
        terminal_outcome: stage.is_terminal ? stage.terminal_outcome : null,
    }, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
    });
}

const stageToDelete = ref(null);

function confirmDeleteStage(stage) {
    stageToDelete.value = stage;
}

function deleteStage() {
    if (! stageToDelete.value) return;

    router.delete(stageToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { stageToDelete.value = null; },
    });
}

function appendStage(pipeline) {
    const payload = newStage[pipeline.id];
    if (!payload || !payload.name.trim()) return;

    router.post(pipeline.add_stage_url, {
        name: payload.name,
        is_terminal: !!payload.is_terminal,
        terminal_outcome: payload.is_terminal ? payload.terminal_outcome : null,
    }, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => {
            errors.value = {};
            newStage[pipeline.id] = { name: '', is_terminal: false, terminal_outcome: null };
        },
    });
}
</script>

<template>
    <Head :title="[__('Manage pipelines'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Manage pipelines')" icon="chart-pie" />

        <div v-if="errors.stage" class="mb-4 rounded-lg bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300" data-leadhub-stage-error>
            {{ errors.stage }}
        </div>

        <Panel v-if="canManage" class="mb-4" :heading="__('New pipeline')">
            <div class="p-4 space-y-4">
                <Field :label="__('Pipeline name')">
                    <Input v-model="name" :placeholder="__('e.g. Sales')" />
                </Field>

                <div class="space-y-2">
                    <div class="text-sm font-medium">{{ __('Stages') }}</div>
                    <div
                        v-for="(stage, index) in stages"
                        :key="index"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <Field :label="__('Name')" class="flex-1 min-w-[12rem]">
                            <Input v-model="stage.name" :placeholder="__('Stage name')" />
                        </Field>
                        <label class="flex items-center gap-1 text-sm pb-2">
                            <Switch v-model="stage.is_terminal" />
                            {{ __('Terminal') }}
                        </label>
                        <Field v-if="stage.is_terminal" :label="__('Outcome')">
                            <Select v-model="stage.terminal_outcome" :options="outcomeOptions" />
                        </Field>
                        <Button icon="trash" size="sm" variant="ghost" @click="removeStage(index)" />
                    </div>
                    <Button :text="__('Add stage')" icon="add" size="sm" variant="ghost" @click="addStage" />
                </div>

                <Button :text="__('Create pipeline')" variant="primary" :disabled="!name.trim()" @click="create" />
            </div>
        </Panel>

        <div class="space-y-3">
            <Panel v-for="pipeline in pipelines" :key="pipeline.id" :data-leadhub-pipeline="pipeline.id">
                <div class="px-4 py-3 flex items-center justify-between border-b border-content-border">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ pipeline.name }}</span>
                        <Badge color="default" :text="pipeline.slug" />
                        <Badge v-if="!pipeline.is_active" color="red" :text="__('Inactive')" />
                    </div>
                    <Link :href="pipeline.board_url" class="text-sm text-primary hover:underline">{{ __('Open board') }}</Link>
                </div>

                <!-- Read-only overview: the stage order as the board renders it. -->
                <div class="px-4 pt-3 flex flex-wrap gap-2" data-leadhub-stage-order>
                    <Badge
                        v-for="stage in draft[pipeline.id] || []"
                        :key="stage.id"
                        :color="stage.is_terminal ? (stage.terminal_outcome === 'won' ? 'green' : 'red') : 'blue'"
                        :text="stage.name"
                    />
                </div>

                <div v-if="canManage" class="p-4 space-y-3">
                    <div
                        v-for="(stage, index) in draft[pipeline.id] || []"
                        :key="stage.id"
                        class="flex flex-wrap items-end gap-2 border-b border-content-border pb-3 last:border-0 last:pb-0"
                        :data-leadhub-stage="stage.id"
                    >
                        <div class="flex flex-col gap-0.5 pb-1.5">
                            <Button
                                icon="chevron-up"
                                size="xs"
                                variant="ghost"
                                :aria-label="__('Move up')"
                                :disabled="index === 0"
                                @click="move(pipeline, index, -1)"
                            />
                            <Button
                                icon="chevron-down"
                                size="xs"
                                variant="ghost"
                                :aria-label="__('Move down')"
                                :disabled="index === (draft[pipeline.id] || []).length - 1"
                                @click="move(pipeline, index, 1)"
                            />
                        </div>

                        <Field :label="__('Name')" class="flex-1 min-w-[12rem]">
                            <Input v-model="stage.name" />
                        </Field>

                        <label class="flex items-center gap-1 text-sm pb-2">
                            <Switch v-model="stage.is_terminal" />
                            {{ __('Terminal') }}
                        </label>

                        <Field v-if="stage.is_terminal" :label="__('Outcome')">
                            <Select v-model="stage.terminal_outcome" :options="outcomeOptions" />
                        </Field>

                        <Button :text="__('Save')" size="sm" variant="default" @click="saveStage(stage)" />

                        <Button
                            icon="trash"
                            size="sm"
                            variant="ghost"
                            :aria-label="__('Delete stage')"
                            @click="confirmDeleteStage(stage)"
                        />

                        <Text v-if="stage.opportunities_count" size="xs" variant="subtle" class="pb-2">
                            {{ stage.opportunities_count }} {{ __('Opportunities') }}
                        </Text>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            :text="__('Save order')"
                            variant="primary"
                            size="sm"
                            :disabled="!orderChanged(pipeline)"
                            data-leadhub-save-order
                            @click="saveOrder(pipeline)"
                        />
                        <Text v-if="orderChanged(pipeline)" size="xs" variant="subtle">
                            {{ __('Order changed but not saved yet.') }}
                        </Text>
                    </div>

                    <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-content-border">
                        <Field :label="__('Add stage')" class="flex-1 min-w-[12rem]">
                            <Input v-model="newStage[pipeline.id].name" :placeholder="__('Stage name')" />
                        </Field>
                        <label class="flex items-center gap-1 text-sm pb-2">
                            <Switch v-model="newStage[pipeline.id].is_terminal" />
                            {{ __('Terminal') }}
                        </label>
                        <Field v-if="newStage[pipeline.id].is_terminal" :label="__('Outcome')">
                            <Select v-model="newStage[pipeline.id].terminal_outcome" :options="outcomeOptions" />
                        </Field>
                        <Button
                            :text="__('Add')"
                            icon="add"
                            size="sm"
                            variant="default"
                            :disabled="!newStage[pipeline.id].name.trim()"
                            @click="appendStage(pipeline)"
                        />
                    </div>
                </div>
            </Panel>
        </div>

        <ConfirmationModal
            :open="stageToDelete !== null"
            :title="__('Delete stage')"
            :body-text="__('Delete this stage? A stage that still holds opportunities is refused.')"
            danger
            :button-text="__('Delete')"
            @cancel="stageToDelete = null"
            @confirm="deleteStage"
        />
    </div>
</template>
