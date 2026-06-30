<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button } from '@statamic/cms/ui';

const props = defineProps(['pipeline', 'pipelines', 'columns', 'canManage']);

function switchPipeline(url) {
    router.visit(url);
}

function moveCard(card, stageId) {
    router.post(card.move_url, { stage_id: stageId }, { preserveScroll: true });
}

function money(value) {
    if (value === null || value === undefined) return null;
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value);
}
</script>

<template>
    <Head :title="[pipeline.name, __('Pipelines'), __('LeadHub')]" />

    <div class="max-w-full mx-auto">
        <Header :title="__('Pipelines')" icon="chart-pie">
            <template #actions>
                <div class="flex gap-1">
                    <Button
                        v-for="p in pipelines"
                        :key="p.id"
                        :text="p.name"
                        size="sm"
                        :variant="p.id === pipeline.id ? 'primary' : 'ghost'"
                        @click="switchPipeline(p.url)"
                    />
                </div>
            </template>
        </Header>

        <div class="flex gap-3 overflow-x-auto pb-4">
            <div
                v-for="column in columns"
                :key="column.id"
                class="flex-shrink-0 w-72"
            >
                <Panel>
                    <div class="px-3 py-2 border-b border-content-border flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-sm">{{ column.name }}</span>
                            <Badge color="gray" :text="String(column.cards.length)" />
                        </div>
                        <Badge
                            v-if="column.is_terminal"
                            :color="column.terminal_outcome === 'won' ? 'green' : 'red'"
                            :text="column.terminal_outcome"
                        />
                    </div>

                    <div class="p-2 space-y-2 min-h-[4rem]">
                        <div
                            v-for="card in column.cards"
                            :key="card.id"
                            class="rounded border border-content-border bg-white dark:bg-dark-700 p-2 shadow-sm"
                        >
                            <div class="font-medium text-sm">{{ card.title }}</div>
                            <a v-if="card.contact_url" :href="card.contact_url" class="text-xs text-blue-600">
                                {{ card.contact_name }}
                            </a>
                            <div class="flex items-center justify-between mt-1">
                                <span v-if="card.value_estimate" class="text-xs text-gray-600 dark:text-gray-300">
                                    {{ money(card.value_estimate) }}
                                </span>
                                <Badge v-if="card.confidence" color="gray" :text="`${card.confidence}%`" />
                            </div>

                            <div v-if="canManage" class="mt-2 flex flex-wrap gap-1">
                                <Button
                                    v-for="target in columns.filter(c => c.id !== column.id)"
                                    :key="target.id"
                                    :text="`→ ${target.name}`"
                                    size="xs"
                                    variant="ghost"
                                    @click="moveCard(card, target.id)"
                                />
                            </div>
                        </div>

                        <p v-if="!column.cards.length" class="text-xs text-gray-400 px-1 py-2">
                            {{ __('No open opportunities in this stage.') }}
                        </p>
                    </div>

                    <div v-if="column.total_value" class="px-3 py-1 border-t border-content-border text-xs text-gray-500">
                        {{ money(column.total_value) }}
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
