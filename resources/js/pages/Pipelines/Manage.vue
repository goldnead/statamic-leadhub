<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button, Field, Input, Select, Switch } from '@statamic/cms/ui';

const props = defineProps(['pipelines', 'storeUrl', 'canManage']);

const name = ref('');
const stages = ref([
    { name: 'New', is_terminal: false, terminal_outcome: null },
    { name: 'Won', is_terminal: true, terminal_outcome: 'won' },
    { name: 'Lost', is_terminal: true, terminal_outcome: 'lost' },
]);

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
</script>

<template>
    <Head :title="[__('Manage pipelines'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Manage pipelines')" icon="chart-pie" />

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
                            <Select
                                v-model="stage.terminal_outcome"
                                :options="[{ value: null, label: '—' }, { value: 'won', label: __('Won') }, { value: 'lost', label: __('Lost') }]"
                            />
                        </Field>
                        <Button icon="trash" size="sm" variant="ghost" @click="removeStage(index)" />
                    </div>
                    <Button :text="__('Add stage')" icon="add" size="sm" variant="ghost" @click="addStage" />
                </div>

                <Button :text="__('Create pipeline')" variant="primary" :disabled="!name.trim()" @click="create" />
            </div>
        </Panel>

        <div class="space-y-3">
            <Panel v-for="pipeline in pipelines" :key="pipeline.id">
                <div class="px-4 py-3 flex items-center justify-between border-b border-content-border">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ pipeline.name }}</span>
                        <Badge color="default" :text="pipeline.slug" />
                        <Badge v-if="!pipeline.is_active" color="red" :text="__('Inactive')" />
                    </div>
                    <Link :href="pipeline.board_url" class="text-sm text-blue-600 hover:underline">{{ __('Open board') }}</Link>
                </div>
                <div class="p-3 flex flex-wrap gap-2">
                    <Badge
                        v-for="stage in pipeline.stages"
                        :key="stage.slug"
                        :color="stage.is_terminal ? (stage.terminal_outcome === 'won' ? 'green' : 'red') : 'blue'"
                        :text="stage.name"
                    />
                </div>
            </Panel>
        </div>
    </div>
</template>
