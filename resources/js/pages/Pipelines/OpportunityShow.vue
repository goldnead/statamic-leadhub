<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Text, Field, Select, Textarea, Description,
    Dropdown, DropdownMenu, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';
import ErrorSummary from '../../support/ErrorSummary.vue';
import { money } from '../../support/money.js';

const props = defineProps([
    'opportunity',   // the deal as it stands
    'stages',        // [{ value, label }] — of this deal's pipeline
    'history',       // newest first; entry 0 of the stored order is the creation
    'tasks',         // every task on this deal, completed ones included
    'tasksEnabled',
    'canManageTasks',
    'canManage',     // manage leadhub opportunities
    'createTaskUrl',
    'editUrl',
    'deleteUrl',
    'moveUrl',
    'boardUrl',
]);

const errors = ref({});
const confirmingDelete = ref(false);
const moving = ref(false);

const stageForm = ref({
    stage_id: props.opportunity.stage_id || '',
    note: '',
});

// The move answers with `back()`, so the page re-renders with the deal in its
// new stage. The select has to follow, or the form keeps offering the move that
// just happened.
watch(() => props.opportunity.stage_id, (value) => {
    stageForm.value.stage_id = value || '';
});

const historyRows = computed(() => props.history || []);
const taskList = computed(() => props.tasks || []);

// Offering the form with one stage in the pipeline would be offering a move to
// where the deal already is.
const canChangeStage = computed(() => !!props.moveUrl && (props.stages || []).length > 1);
const stageChanged = computed(
    () => !!stageForm.value.stage_id && stageForm.value.stage_id !== props.opportunity.stage_id
);

function changeStage() {
    if (moving.value || !stageChanged.value) return;

    moving.value = true;
    errors.value = {};

    router.post(props.moveUrl, {
        stage_id: stageForm.value.stage_id,
        note: stageForm.value.note || null,
    }, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; stageForm.value.note = ''; },
        onFinish: () => { moving.value = false; },
    });
}

function completeTask(task) {
    router.post(task.complete_url, {}, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
    });
}

function destroy() {
    router.delete(props.deleteUrl, {
        preserveScroll: true,
        // "This opportunity still has N tasks" arrives as an error bag on a
        // 422. It has to land on the screen, or the button looks broken.
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { confirmingDelete.value = false; },
    });
}
</script>

<template>
    <Head :title="[opportunity.title, __('Opportunities'), __('LeadHub')]" />

    <div class="max-w-page mx-auto" data-leadhub-opportunity-show>
        <Header :title="opportunity.title" icon="charts-donut-graph">
            <div class="flex items-center gap-2">
                <Button
                    :text="__('leadhub::pipelines.back_to_board')"
                    variant="ghost"
                    data-leadhub-opportunity-board
                    @click="router.visit(boardUrl)"
                />
                <Button
                    v-if="editUrl"
                    :text="__('Edit')"
                    icon="edit"
                    variant="default"
                    data-leadhub-edit-opportunity
                    @click="router.visit(editUrl)"
                />
                <!-- `danger` is core's confirm button inside a modal; a
                     destructive page action goes in the "…" menu. -->
                <Dropdown v-if="deleteUrl">
                    <DropdownMenu>
                        <DropdownItem
                            :text="__('Delete')"
                            icon="trash"
                            variant="destructive"
                            data-leadhub-delete-opportunity
                            @click="confirmingDelete = true"
                        />
                    </DropdownMenu>
                </Dropdown>
            </div>
        </Header>

        <ErrorSummary :errors="errors" :fields="['stage_id', 'note', 'opportunity']" />

        <!--
            The single-column grid utility is deliberately absent: a grid falls
            back to one column on its own, and every sibling addon shipping a
            Tailwind build emits that same bare, breakpoint-less rule into the
            shared `addon-utilities` layer. Media queries add no specificity, so
            whichever addon stylesheet loads last wins over an earlier `lg:`
            variant and flattens that addon's grid to one column at every width.
            Leaving the class off means no foreign rule can match this element.

            Do not name the class in a comment either — Tailwind scans comments
            as candidates, so writing it here is enough to emit the very rule
            this avoids.

            `*:min-w-0` on the container keeps what the utility's `minmax(0,1fr)`
            track provided: the implicit column is `auto`, which a long deal
            title or an unbroken note would push past the container.
        -->
        <div class="grid lg:grid-cols-3 gap-4 *:min-w-0">
            <div class="lg:col-span-1 space-y-4">
                <Panel :heading="__('Details')">
                    <Card>
                        <div class="space-y-3 p-1" data-leadhub-opportunity-facts>
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge v-if="opportunity.pipeline_name" color="default" :text="opportunity.pipeline_name" />
                                <Badge
                                    v-if="opportunity.outcome"
                                    :color="opportunity.outcome === 'won' ? 'green' : 'red'"
                                    :text="opportunity.outcome === 'won' ? __('Won') : __('Lost')"
                                    data-leadhub-opportunity-outcome
                                />
                                <Badge v-else color="blue" :text="__('Open')" data-leadhub-opportunity-outcome />
                            </div>

                            <div>
                                <div class="text-xs text-gray-500 uppercase">{{ __('Contact') }}</div>
                                <Link
                                    v-if="opportunity.contact_url"
                                    :href="opportunity.contact_url"
                                    class="text-sm font-medium hover:underline"
                                    data-leadhub-opportunity-contact
                                >{{ opportunity.contact_name }}</Link>
                                <span v-else class="text-sm text-gray-400">—</span>
                            </div>

                            <div v-if="opportunity.company_name">
                                <div class="text-xs text-gray-500 uppercase">{{ __('Company') }}</div>
                                <Link
                                    v-if="opportunity.company_url"
                                    :href="opportunity.company_url"
                                    class="text-sm hover:underline"
                                    data-leadhub-opportunity-company
                                >{{ opportunity.company_name }}</Link>
                                <span v-else class="text-sm">{{ opportunity.company_name }}</span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <div class="text-xs text-gray-500 uppercase">{{ __('Value') }}</div>
                                    <div class="text-sm font-medium" data-leadhub-opportunity-value>
                                        {{ money(opportunity.value_estimate) || '—' }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 uppercase">{{ __('Confidence') }}</div>
                                    <div class="text-sm">{{ opportunity.confidence }}%</div>
                                </div>
                            </div>

                            <div>
                                <div class="text-xs text-gray-500 uppercase">{{ __('Owner') }}</div>
                                <div class="text-sm">{{ opportunity.owner_name || __('Unassigned') }}</div>
                            </div>

                            <dl class="grid gap-x-3 gap-y-1 text-xs sm:grid-cols-[auto_1fr]">
                                <dt><Text size="xs" variant="subtle">{{ __('Created') }}</Text></dt>
                                <dd><Text size="xs">{{ opportunity.created_at || '—' }}</Text></dd>

                                <dt><Text size="xs" variant="subtle">{{ __('leadhub::pipelines.last_activity') }}</Text></dt>
                                <dd><Text size="xs">{{ opportunity.last_activity_at || '—' }}</Text></dd>

                                <!--
                                    Dates that only exist while the status says
                                    so. A won date beside an open deal is not a
                                    detail, it is a contradiction — see the
                                    controller and StageTransitionService.
                                -->
                                <!-- The generic close date only where no
                                     outcome date takes its place: the service
                                     writes both in the same second, and one
                                     fact printed twice is noise. -->
                                <template v-if="opportunity.closed_at && !opportunity.won_at && !opportunity.lost_at">
                                    <dt><Text size="xs" variant="subtle">{{ __('leadhub::pipelines.closed_at') }}</Text></dt>
                                    <dd><Text size="xs" data-leadhub-opportunity-closed-at>{{ opportunity.closed_at }}</Text></dd>
                                </template>
                                <template v-if="opportunity.won_at">
                                    <dt><Text size="xs" variant="subtle">{{ __('leadhub::pipelines.won_at') }}</Text></dt>
                                    <dd><Text size="xs" data-leadhub-opportunity-won-at>{{ opportunity.won_at }}</Text></dd>
                                </template>
                                <template v-if="opportunity.lost_at">
                                    <dt><Text size="xs" variant="subtle">{{ __('leadhub::pipelines.lost_at') }}</Text></dt>
                                    <dd><Text size="xs" data-leadhub-opportunity-lost-at>{{ opportunity.lost_at }}</Text></dd>
                                </template>
                            </dl>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('leadhub::pipelines.current_stage')">
                    <Card>
                        <div class="space-y-3 p-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    :color="opportunity.stage_is_terminal ? 'default' : 'blue'"
                                    :text="opportunity.stage_name"
                                    data-leadhub-opportunity-stage
                                />
                            </div>

                            <!--
                                Moving the deal from here, with the note. Before
                                v2.4.0 a stage change was a drag on the board or
                                a side effect of the edit form, and only the
                                board's endpoint ever wrote the note — the one
                                thing that tells a later reader *why* the deal
                                went on. This form posts to that same endpoint.
                            -->
                            <form v-if="canChangeStage" data-leadhub-opportunity-stage-form @submit.prevent="changeStage">
                                <Field :label="__('leadhub::pipelines.change_stage')" :error="errors.stage_id">
                                    <Select
                                        v-model="stageForm.stage_id"
                                        :options="stages"
                                        data-leadhub-opportunity-stage-select
                                    />
                                </Field>

                                <Field
                                    :label="__('leadhub::pipelines.change_stage_note')"
                                    :error="errors.note"
                                    class="mt-3"
                                >
                                    <Textarea
                                        v-model="stageForm.note"
                                        :rows="2"
                                        data-leadhub-opportunity-stage-note
                                    />
                                </Field>

                                <Description class="mt-1">{{ __('leadhub::pipelines.change_stage_hint') }}</Description>

                                <Button
                                    type="submit"
                                    class="mt-3"
                                    :text="__('leadhub::pipelines.change_stage_submit')"
                                    variant="primary"
                                    size="sm"
                                    :disabled="!stageChanged || moving"
                                    data-leadhub-opportunity-stage-submit
                                />
                            </form>
                        </div>
                    </Card>
                </Panel>
            </div>

            <div class="lg:col-span-2 space-y-4">
                <!--
                    The history, read out of `leadhub_stage_transitions` — a
                    table that has been written since the pipelines module
                    shipped and that nothing had ever read. Newest first, like
                    the contact timeline.
                -->
                <Panel :heading="__('leadhub::pipelines.history')">
                    <Card>
                        <div class="p-1" data-leadhub-opportunity-history>
                            <ul class="-my-3 divide-y divide-content-border">
                                <li
                                    v-for="row in historyRows"
                                    :key="row.key"
                                    class="py-3"
                                    :data-leadhub-history-entry="row.key"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3 text-sm">
                                        <div class="min-w-0">
                                            <span v-if="row.is_start" class="text-gray-500">
                                                {{ __('leadhub::pipelines.history_created') }} ·
                                            </span>
                                            <template v-else>
                                                <span class="text-gray-500">{{ row.from_stage_name }}</span>
                                                <span class="text-gray-400" aria-hidden="true"> → </span>
                                            </template>
                                            <span class="font-medium">{{ row.to_stage_name }}</span>
                                        </div>
                                        <Text size="xs" variant="subtle" class="shrink-0">{{ row.occurred_at }}</Text>
                                    </div>

                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span v-if="row.duration_label" :data-leadhub-history-duration="row.key">
                                            {{ row.duration_label }}
                                        </span>
                                        <!-- "ongoing" is about the clock, and
                                             on a deal that is closed the clock
                                             reads "how long ago did we win
                                             this", not "how long has this been
                                             sitting here". The number still
                                             answers that; the word would not. -->
                                        <Badge
                                            v-if="row.is_running"
                                            color="default"
                                            pill
                                            :text="__('leadhub::pipelines.duration_running')"
                                        />
                                        <span v-if="row.actor_label">· {{ row.actor_label }}</span>
                                    </div>

                                    <p
                                        v-if="row.note"
                                        class="mt-2 text-sm text-gray-900 dark:text-gray-200"
                                        :data-leadhub-history-note="row.key"
                                    >{{ row.note }}</p>
                                </li>
                            </ul>

                            <!-- The footnote belongs inside the panel it
                                 explains. Between two panels it reads as a
                                 heading for the next one. -->
                            <p class="mt-4 border-t border-content-border pt-3 text-xs text-gray-500 dark:text-gray-400">
                                <!-- The footnote has to follow the fix above:
                                     the last stretch of a closed deal ends at
                                     the close, so "runs until now" would be
                                     the old, wrong sentence under the new,
                                     right number. -->
                                {{ opportunity.is_open
                                    ? __('leadhub::pipelines.history_hint')
                                    : __('leadhub::pipelines.history_hint_closed') }}
                            </p>
                        </div>
                    </Card>
                </Panel>

                <!--
                    The same list the edit form carries, for the same reason:
                    every task, completed ones included, because that is what
                    the delete refusal counts.
                -->
                <div v-if="tasksEnabled" data-leadhub-opportunity-tasks>
                    <Panel :heading="`${__('leadhub::tasks.title')} (${taskList.length})`">
                        <Card>
                            <div class="p-1">
                                <Text v-if="!taskList.length" size="sm" variant="subtle" data-leadhub-opportunity-tasks-empty>
                                    {{ __('leadhub::pipelines.opportunity_tasks_empty') }}
                                </Text>

                                <ul v-else class="-my-3 divide-y divide-content-border">
                                    <li
                                        v-for="task in taskList"
                                        :key="task.id"
                                        class="flex flex-wrap items-center gap-2 py-3"
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
                                            v-if="task.is_open && canManageTasks"
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
                </div>
            </div>
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
