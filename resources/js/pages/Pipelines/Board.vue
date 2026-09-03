<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Badge, Button, Text, Icon, Select, EmptyStateMenu, EmptyStateItem } from '@statamic/cms/ui';
import { money } from '../../support/money.js';

const props = defineProps([
    'pipeline', 'pipelines', 'columns', 'manageUrl', 'canConfigure', 'canManage',
    'closedWindow',         // 'none' | '30d' | '90d' | '365d' | 'all'
    'closedWindowOptions',  // [{ value, label }]
    'totals',               // { open, closed, won, lost }
    'newOpportunityUrl',    // string | null — null hides every create button
    'canManageOpportunities',
]);

/**
 * Creating from a column carries the stage with it, so the form opens on the
 * column the user pointed at rather than on the pipeline's first stage. The
 * pipeline goes along because the create screen can switch between pipelines.
 */
function newOpportunity(columnId = null) {
    if (!props.newOpportunityUrl) return;
    const params = new URLSearchParams();
    if (props.pipeline) params.set('pipeline', String(props.pipeline.id));
    if (columnId !== null) params.set('stage', String(columnId));
    const query = params.toString();
    router.visit(query ? `${props.newOpportunityUrl}?${query}` : props.newOpportunityUrl);
}

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

// Closed deals used to be filtered out entirely, which made a win look like a
// deleted record and left the win column summing to 0. The window is a query
// parameter so the chosen view is shareable and survives a reload.
const closedWindow = ref(props.closedWindow || '30d');

watch(() => props.closedWindow, (value) => { closedWindow.value = value || '30d'; });

function changeClosedWindow(value) {
    closedWindow.value = value;
    router.get(window.location.pathname, { closed: value }, {
        preserveScroll: true,
        preserveState: false,
    });
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

function columnClosedValue(column) {
    return column.cards
        .filter((card) => card.is_closed)
        .reduce((sum, card) => sum + (Number(card.value_estimate) || 0), 0);
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
        <Header :title="__('Pipelines')" icon="charts-donut-graph" />

        <EmptyStateMenu
            v-if="canConfigure"
            :heading="__('No pipeline yet — create one to start tracking opportunities.')"
        >
            <EmptyStateItem
                :href="manageUrl"
                icon="charts-donut-graph"
                :heading="__('Create a pipeline')"
                :description="__('Define the stages your opportunities move through, then manage them on the board.')"
            />
        </EmptyStateMenu>

        <div v-else class="text-center py-16">
            <!-- `chart-pie` is not in the icon set; an unknown name renders an
                 empty box, silently. -->
            <Icon name="charts-donut-graph" class="size-12 text-gray-400 mx-auto mb-4" />
            <p class="text-gray-500 dark:text-gray-400">{{ __('No pipeline has been configured yet.') }}</p>
        </div>
    </div>

    <div v-else class="max-w-full mx-auto">
        <Header :title="__('Pipelines')" icon="charts-donut-graph">
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
                    v-if="newOpportunityUrl"
                    :text="__('New opportunity')"
                    icon="plus"
                    size="sm"
                    variant="primary"
                    data-leadhub-new-opportunity
                    @click="newOpportunity()"
                />
                <Select
                    :model-value="closedWindow"
                    :options="closedWindowOptions"
                    class="w-56"
                    data-leadhub-closed-filter
                    @update:model-value="changeClosedWindow"
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
        </Header>

        <div v-if="totals" class="flex flex-wrap items-center gap-4 px-1 pb-3 text-xs text-gray-500" data-leadhub-board-totals>
            <span>{{ __('Open') }}: <span class="font-medium text-gray-900 dark:text-gray-200">{{ money(totals.open) }}</span></span>
            <span>{{ __('Won') }}: <span class="font-medium text-green-600 dark:text-green-400">{{ money(totals.won) }}</span></span>
            <span>{{ __('Lost') }}: <span class="font-medium text-red-600 dark:text-red-400">{{ money(totals.lost) }}</span></span>
        </div>

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
                            <Badge color="default" size="sm" :text="String(column.cards.length)" pill />
                        </div>
                        <!-- The raw column value ("won") used to be printed
                             here. Same defect as on the contact screen. -->
                        <Badge
                            v-if="column.is_terminal"
                            pill
                            :color="column.terminal_outcome === 'won' ? 'green' : 'red'"
                            :text="__('leadhub::pipelines.opportunity_outcome.' + column.terminal_outcome)"
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
                            class="group relative rounded-xl bg-content-bg dark:bg-gray-850 ring ring-gray-200 dark:ring-gray-700/80 shadow-ui-md p-3 transition"
                            :class="[
                                canManage ? 'cursor-grab active:cursor-grabbing' : '',
                                draggingCardId === card.id ? 'opacity-50' : '',
                                card.is_closed ? 'opacity-90 ring-dashed' : '',
                            ]"
                            :data-leadhub-closed="card.is_closed ? card.outcome : null"
                            @dragstart="onDragStart($event, card.id, column.id)"
                            @dragend="onDragEnd"
                        >
                            <div v-if="card.is_closed" class="mb-2 flex items-center gap-1.5">
                                <Badge
                                    :color="card.outcome === 'won' ? 'green' : 'red'"
                                    size="sm"
                                    :text="card.outcome === 'won' ? __('Won') : __('Lost')"
                                 pill />
                                <Text v-if="card.closed_at" size="xs" variant="subtle">{{ card.closed_at }}</Text>
                            </div>

                            <div class="flex items-start gap-2.5">
                                <div
                                    class="size-7 shrink-0 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-2xs font-medium text-gray-600 dark:text-gray-300"
                                    aria-hidden="true"
                                >
                                    {{ initials(card.contact_name) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <!-- The card title opens the deal. It was
                                         plain text for six releases because
                                         there was nothing to open. -->
                                    <!-- `draggable="false"` so a drag starting
                                         on the title bubbles to the card. An
                                         anchor is draggable by default and
                                         would drag its own URL instead. -->
                                    <Link
                                        :href="card.show_url"
                                        :draggable="false"
                                        class="block truncate text-sm font-medium text-gray-900 hover:underline dark:text-gray-100"
                                        data-leadhub-open-opportunity
                                    >{{ card.title }}</Link>
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
                                <Text v-if="card.value_estimate" size="sm" class="font-medium text-gray-900 dark:text-gray-200">
                                    {{ money(card.value_estimate) }}
                                </Text>
                                <Badge v-if="card.confidence" color="default" size="sm" :text="`${card.confidence}%`" pill />
                            </div>

                            <div v-if="canManage || canManageOpportunities" class="mt-2 flex flex-wrap items-center gap-1 border-t border-content-border pt-2">
                                <Button
                                    v-if="canManageOpportunities"
                                    icon="edit"
                                    size="xs"
                                    variant="ghost"
                                    :aria-label="__('Edit opportunity')"
                                    data-leadhub-edit-opportunity
                                    @click="router.visit(card.edit_url)"
                                />
                                <template v-if="canManage">
                                    <Button
                                        v-for="target in board.filter(c => c.id !== column.id)"
                                        :key="target.id"
                                        :text="`→ ${target.name}`"
                                        size="xs"
                                        variant="ghost"
                                        @click="moveCard(card.id, column.id, target.id)"
                                    />
                                </template>
                            </div>
                        </div>

                        <p v-if="!column.cards.length" class="text-xs text-gray-400 px-1 py-2">
                            {{ closedWindow === 'none' ? __('No open opportunities in this stage.') : __('Nothing in this stage.') }}
                        </p>

                        <Button
                            v-if="newOpportunityUrl"
                            :text="__('New opportunity')"
                            icon="plus"
                            size="xs"
                            variant="ghost"
                            class="w-full justify-center"
                            :data-leadhub-new-opportunity-in="column.id"
                            @click="newOpportunity(column.id)"
                        />
                    </div>

                    <div
                        v-if="columnValue(column)"
                        class="px-3 py-1 border-t border-content-border text-xs text-gray-500 flex items-center justify-between gap-2"
                        data-leadhub-column-total
                    >
                        <span class="font-medium text-gray-900 dark:text-gray-200">{{ money(columnValue(column)) }}</span>
                        <span v-if="columnClosedValue(column)" class="truncate">
                            {{ __('incl. closed') }} {{ money(columnClosedValue(column)) }}
                        </span>
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
