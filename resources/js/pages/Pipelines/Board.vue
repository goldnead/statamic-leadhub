<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button, Text, Icon, EmptyStateMenu, EmptyStateItem } from '@statamic/cms/ui';

const props = defineProps(['pipeline', 'pipelines', 'columns', 'manageUrl', 'canConfigure', 'canManage']);

// Feature is enabled but no pipeline exists yet: show a native empty state.
const isEmpty = computed(() => !props.pipeline || !props.pipelines || props.pipelines.length === 0);

// Local, mutable copy of the server columns so drag & drop can update the UI
// optimistically. Inertia replaces `props.columns` after the move persists,
// which re-syncs this local state to the authoritative server order.
const board = ref(cloneColumns(props.columns));

const draggingCardId = ref(null);
const dragFromColumnId = ref(null);
const dragOverColumnId = ref(null);

watch(() => props.columns, (cols) => {
    board.value = cloneColumns(cols);
});

function cloneColumns(cols) {
    return (cols || []).map((c) => ({ ...c, cards: (c.cards || []).map((card) => ({ ...card })) }));
}

function switchPipeline(url) {
    router.visit(url);
}

function money(value) {
    if (value === null || value === undefined) return null;
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value);
}

function initials(name) {
    if (!name) return '—';
    const parts = name.trim().split(/\s+/);
    const raw = parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0];
    return raw.toUpperCase();
}

function columnValue(column) {
    return column.cards.reduce((sum, card) => sum + (Number(card.value_estimate) || 0), 0);
}

/** Optimistically move a card between local columns, then persist. */
function moveCard(cardId, fromColumnId, toColumnId) {
    if (fromColumnId === toColumnId) return;

    const from = board.value.find((c) => c.id === fromColumnId);
    const to = board.value.find((c) => c.id === toColumnId);
    if (!from || !to) return;

    const index = from.cards.findIndex((c) => c.id === cardId);
    if (index === -1) return;

    const [card] = from.cards.splice(index, 1);
    to.cards.push(card);

    router.post(card.move_url, { stage_id: toColumnId }, { preserveScroll: true });
}

// --- Native HTML5 drag & drop (no extra dependency needed) ---
function onDragStart(event, cardId, columnId) {
    draggingCardId.value = cardId;
    dragFromColumnId.value = columnId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(cardId));
}

function onDragEnd() {
    draggingCardId.value = null;
    dragFromColumnId.value = null;
    dragOverColumnId.value = null;
}

function onDragEnter(columnId) {
    dragOverColumnId.value = columnId;
}

function onDrop(columnId) {
    const cardId = draggingCardId.value;
    const fromColumnId = dragFromColumnId.value;
    onDragEnd();
    if (cardId === null || fromColumnId === null) return;
    moveCard(cardId, fromColumnId, columnId);
}
</script>

<template>
    <Head :title="isEmpty ? [__('Pipelines'), __('LeadHub')] : [pipeline.name, __('Pipelines'), __('LeadHub')]" />

    <div v-if="isEmpty" class="max-w-page mx-auto">
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="chart-pie" class="size-5 text-gray-500" />
                {{ __('Pipelines') }}
            </h1>
        </header>

        <EmptyStateMenu
            v-if="canConfigure"
            :heading="__('No pipeline yet — create one to start tracking opportunities.')"
        >
            <EmptyStateItem
                :href="manageUrl"
                icon="chart-pie"
                :heading="__('Create a pipeline')"
                :description="__('Define the stages your opportunities move through, then manage them on the board.')"
            />
        </EmptyStateMenu>

        <div v-else class="text-center py-16">
            <Icon name="chart-pie" class="size-12 text-gray-400 mx-auto mb-4" />
            <p class="text-gray-500 dark:text-gray-400">{{ __('No pipeline has been configured yet.') }}</p>
        </div>
    </div>

    <div v-else class="max-w-full mx-auto">
        <Header :title="__('Pipelines')" icon="chart-pie">
            <template #actions>
                <div class="flex gap-1 items-center">
                    <Button
                        v-for="p in pipelines"
                        :key="p.id"
                        :text="p.name"
                        size="sm"
                        :variant="p.id === pipeline.id ? 'primary' : 'ghost'"
                        @click="switchPipeline(p.url)"
                    />
                    <Button
                        v-if="canConfigure"
                        :text="__('Manage')"
                        icon="cog"
                        size="sm"
                        variant="ghost"
                        @click="switchPipeline(manageUrl)"
                    />
                </div>
            </template>
        </Header>

        <div class="flex gap-3 overflow-x-auto pb-4">
            <div
                v-for="column in board"
                :key="column.id"
                class="flex-shrink-0 w-72"
            >
                <Panel>
                    <div class="px-3 py-2 border-b border-content-border flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ column.name }}</span>
                            <Badge color="gray" size="sm" :text="String(column.cards.length)" />
                        </div>
                        <Badge
                            v-if="column.is_terminal"
                            :color="column.terminal_outcome === 'won' ? 'green' : 'red'"
                            :text="column.terminal_outcome"
                        />
                    </div>

                    <div
                        class="p-2 space-y-2 min-h-[4rem] rounded-b-xl transition-colors"
                        :class="dragOverColumnId === column.id ? 'bg-gray-50 dark:bg-gray-800/60 ring-2 ring-inset ring-blue-400/70' : ''"
                        @dragover.prevent
                        @dragenter.prevent="onDragEnter(column.id)"
                        @drop.prevent="onDrop(column.id)"
                    >
                        <div
                            v-for="card in column.cards"
                            :key="card.id"
                            :draggable="canManage"
                            class="group relative rounded-xl bg-white dark:bg-gray-850 ring ring-gray-200 dark:ring-gray-700/80 shadow-ui-md p-3 transition"
                            :class="[
                                canManage ? 'cursor-grab active:cursor-grabbing' : '',
                                draggingCardId === card.id ? 'opacity-50' : '',
                            ]"
                            @dragstart="onDragStart($event, card.id, column.id)"
                            @dragend="onDragEnd"
                        >
                            <div class="flex items-start gap-2.5">
                                <div
                                    class="size-7 shrink-0 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-2xs font-medium text-gray-600 dark:text-gray-300"
                                    aria-hidden="true"
                                >
                                    {{ initials(card.contact_name) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ card.title }}</div>
                                    <Link
                                        v-if="card.contact_url"
                                        :href="card.contact_url"
                                        class="block truncate text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:underline"
                                    >
                                        {{ card.contact_name }}
                                    </Link>
                                </div>
                            </div>

                            <div v-if="card.value_estimate || card.confidence" class="mt-2.5 flex items-center justify-between gap-2">
                                <Text v-if="card.value_estimate" size="sm" class="font-medium text-gray-700 dark:text-gray-200">
                                    {{ money(card.value_estimate) }}
                                </Text>
                                <Badge v-if="card.confidence" color="gray" size="sm" :text="`${card.confidence}%`" />
                            </div>

                            <div v-if="canManage" class="mt-2 flex flex-wrap gap-1 border-t border-content-border pt-2">
                                <Button
                                    v-for="target in board.filter(c => c.id !== column.id)"
                                    :key="target.id"
                                    :text="`→ ${target.name}`"
                                    size="xs"
                                    variant="ghost"
                                    @click="moveCard(card.id, column.id, target.id)"
                                />
                            </div>
                        </div>

                        <p v-if="!column.cards.length" class="text-xs text-gray-400 px-1 py-2">
                            {{ __('No open opportunities in this stage.') }}
                        </p>
                    </div>

                    <div v-if="columnValue(column)" class="px-3 py-1 border-t border-content-border text-xs text-gray-500">
                        {{ money(columnValue(column)) }}
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
