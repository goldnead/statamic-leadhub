<script setup>
import axios from 'axios';
import { ref, reactive, watch, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Alert, Badge, Button, Field, Input, Select, Switch, Text } from '@statamic/cms/ui';

const props = defineProps([
    'segment',      // null on create, else { id, name, handle, description, is_active, rules, managed_by, update_url, delete_url }
    'storeUrl',     // present on create
    'previewUrl',
    'indexUrl',
    'vocabulary',   // { fields, field_operators, custom_fields, tag_operators, event_operators, geo_operators, countries, statuses }
]);

const isEdit = computed(() => !! props.segment);

// A segment another addon maintains (a concert series' radius segment). Its
// name and rule come back on that addon's next sync, so they are shown, not
// offered for editing — the server ignores them on update as well.
const managedBy = computed(() => props.segment?.managed_by ?? null);
const locked = computed(() => !! managedBy.value);

// Declared before the form: normalizeCondition() reads them while the form is built.
const countries = computed(() => props.vocabulary.countries ?? ['DE']);
const defaultCountry = computed(() => countries.value[0] ?? 'DE');

const form = reactive({
    name: props.segment?.name ?? '',
    handle: props.segment?.handle ?? '',
    description: props.segment?.description ?? '',
    is_active: props.segment ? !! props.segment.is_active : true,
    rules: normalizeRules(props.segment?.rules),
});

function normalizeRules(rules) {
    if (! rules || typeof rules !== 'object') {
        return { match: 'all', conditions: [] };
    }
    return {
        match: rules.match === 'any' ? 'any' : 'all',
        conditions: Array.isArray(rules.conditions) ? rules.conditions.map(normalizeCondition) : [],
    };
}

// Every shape the evaluator knows survives a round trip through this form.
// Before, anything that was not a tag or an event came back as a `field`
// condition, so opening and saving a segment rewrote its custom-field and geo
// conditions into ones that match nobody.
function normalizeCondition(c) {
    // A nested group: the builder does not edit groups, but must not destroy
    // one either. Kept as it is and shown as a group.
    if (Array.isArray(c.conditions)) return { ...c, type: 'group' };

    const type = c.type ?? 'field';
    if (type === 'tag') return { type: 'tag', operator: c.operator ?? 'has', value: c.value ?? '' };
    if (type === 'event') return { type: 'event', operator: c.operator ?? 'has', event: c.event ?? '', within_days: c.within_days ?? null };
    if (type === 'custom') return { type: 'custom', field: c.field ?? '', operator: c.operator ?? 'eq', value: c.value ?? '' };
    if (type === 'geo') {
        return {
            type: 'geo',
            operator: c.operator === 'outside_km' ? 'outside_km' : 'within_km',
            plz: String(c.plz ?? c.postal_code ?? ''),
            value: Number(c.value ?? c.radius ?? 30),
            country: String(c.country ?? defaultCountry.value),
        };
    }
    return { type: 'field', field: c.field ?? props.vocabulary.fields[0], operator: c.operator ?? 'eq', value: c.value ?? '' };
}

const conditionLabels = {
    field: __('Field'),
    custom: __('Custom field'),
    tag: __('Tag'),
    event: __('Event'),
    geo: __('Postal code radius'),
    group: __('Condition group'),
};

const countryOptions = computed(() => countries.value.map((c) => ({ value: c, label: c })));
const geoOperatorOptions = computed(() => (props.vocabulary.geo_operators ?? ['within_km', 'outside_km']).map((o) => ({
    value: o,
    label: o === 'outside_km' ? __('outside a radius of') : __('within a radius of'),
})));

function addFieldCondition() {
    form.rules.conditions.push({ type: 'field', field: props.vocabulary.fields[0], operator: 'eq', value: '' });
}

function customFieldByHandle(handle) {
    return (props.vocabulary.custom_fields || []).find((f) => f.handle === handle);
}

function operatorsFor(handle) {
    return customFieldByHandle(handle)?.operators ?? [];
}

function optionsFor(handle) {
    return customFieldByHandle(handle)?.options ?? [];
}

// The operator list changes with the field, so an operator that made sense for
// the old one must not survive the switch — it would be saved, evaluated as
// unknown, and match nobody.
function onCustomFieldChange(condition) {
    const erlaubt = operatorsFor(condition.field);

    if (!erlaubt.includes(condition.operator)) {
        condition.operator = erlaubt[0] ?? 'eq';
    }

    condition.value = '';
}

function addCustomCondition() {
    const first = (props.vocabulary.custom_fields || [])[0];

    if (!first) {
        return;
    }

    form.rules.conditions.push({
        type: 'custom',
        field: first.handle,
        operator: first.operators[0] ?? 'eq',
        value: '',
    });
}
function addTagCondition() {
    form.rules.conditions.push({ type: 'tag', operator: 'has', value: '' });
}
function addEventCondition() {
    form.rules.conditions.push({ type: 'event', operator: 'has', event: '', within_days: null });
}
function addGeoCondition() {
    form.rules.conditions.push({ type: 'geo', operator: 'within_km', plz: '', value: 30, country: defaultCountry.value });
}
function removeCondition(index) {
    form.rules.conditions.splice(index, 1);
}

// -- Live member-count preview (debounced) --
const previewCount = ref(null);
const withoutPostalCode = ref(null);
const places = ref({});
const previewing = ref(false);
let previewTimer = null;

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 400);
}

function runPreview() {
    previewing.value = true;
    // A read, so a GET. The endpoint answers JSON rather than an Inertia page,
    // which rules out router.get(); axios on a GET does not touch Inertia's
    // progress bar, toasts or dirty-state guard, so nothing is bypassed.
    axios.get(props.previewUrl, { params: { rules: form.rules } })
        .then((res) => {
            previewCount.value = res.data.count;
            withoutPostalCode.value = res.data.without_postal_code ?? null;
            places.value = res.data.places ?? {};
        })
        .catch(() => { previewCount.value = null; })
        .finally(() => { previewing.value = false; });
}

watch(() => form.rules, schedulePreview, { deep: true });

// An existing segment opens with its count, not with a dash until somebody
// touches a rule.
if (form.rules.conditions.length) {
    runPreview();
}

function placeKey(condition) {
    return `${(condition.country || defaultCountry.value).toUpperCase()}:${String(condition.plz || '').replace(/[\s.]+/g, '').toUpperCase()}`;
}

/** "Köln", null for a code the directory does not know, undefined while not yet asked. */
function placeFor(condition) {
    if (! String(condition.plz || '').trim()) return undefined;
    return places.value[placeKey(condition)];
}

function submit() {
    if (! form.name.trim()) return;
    const payload = {
        name: form.name,
        handle: form.handle || null,
        description: form.description || null,
        is_active: form.is_active,
        // A group goes back exactly as it came; the `type` was only for display.
        rules: {
            ...form.rules,
            conditions: form.rules.conditions.map(({ type, ...rest }) => (type === 'group' ? rest : { type, ...rest })),
        },
    };
    if (isEdit.value) {
        router.patch(props.segment.update_url, payload, { preserveScroll: true });
    } else {
        router.post(props.storeUrl, payload);
    }
}
</script>

<template>
    <Head :title="[isEdit ? __('Edit segment') : __('New segment'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="isEdit ? __('Edit segment') : __('New segment')" icon="filter">
            <Button :href="indexUrl" :text="__('Back')" variant="ghost" />
            <Button
                v-if="managedBy?.url"
                :href="managedBy.url"
                :text="__('Open :name', { name: managedBy.label || managedBy.source })"
                icon="external-link"
                variant="default"
                data-leadhub-managed-link
            />
            <Button :text="__('Save')" variant="primary" :disabled="!form.name.trim()" @click="submit" />
        </Header>

        <Alert
            v-if="managedBy"
            class="mb-4"
            icon="padlock-locked"
            :heading="__('Managed by :name', { name: managedBy.label || managedBy.source })"
            :text="__('Name and rules are set there and written back on its next sync, so they are read-only here. Description and active state stay yours.')"
            data-leadhub-managed-alert
        />

        <Panel class="mb-4" :heading="__('Details')">
            <Card>
                <div class="space-y-6 p-1">
                    <Field :label="__('Name')">
                        <Input v-model="form.name" :read-only="locked" :placeholder="__('e.g. Engaged buyers')" />
                    </Field>
                    <Field :label="__('Handle')" :instructions="__('Stable identifier used by consumers. Leave empty to derive from the name.')">
                        <Input v-model="form.handle" :read-only="locked" :placeholder="__('engaged-buyers')" />
                    </Field>
                    <Field :label="__('Description')">
                        <Input v-model="form.description" />
                    </Field>
                    <Field :label="__('Active')" :instructions="__('Only active segments are evaluated and exposed to consumers.')">
                        <Switch v-model="form.is_active" />
                    </Field>
                </div>
            </Card>
        </Panel>

        <Panel class="mb-4" :heading="__('Rules')">
            <Card>
                <div class="space-y-4 p-1">
                    <Field :label="__('Match')" :instructions="__('Whether a contact must satisfy all or any of the conditions below.')">
                        <Select
                            v-model="form.rules.match"
                            class="w-56"
                            :read-only="locked"
                            :options="[{ value: 'all', label: __('all conditions') }, { value: 'any', label: __('any condition') }]"
                        />
                    </Field>

                    <p v-if="!form.rules.conditions.length" class="text-sm text-gray-500 dark:text-gray-400 italic">
                        {{ __('No conditions yet — every contact matches. Add a condition below to narrow the segment.') }}
                    </p>

                    <div
                        v-for="(condition, index) in form.rules.conditions"
                        :key="index"
                        class="rounded-lg border border-content-border bg-gray-50 dark:bg-gray-900/40 p-3 space-y-3"
                        :data-leadhub-condition="condition.type"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Badge :text="conditionLabels[condition.type] ?? condition.type" color="blue" pill />
                            <Button
                                v-if="!locked"
                                icon="trash"
                                size="sm"
                                variant="subtle"
                                :aria-label="__('Remove condition')"
                                @click="removeCondition(index)"
                            />
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            <!-- field condition -->
                            <template v-if="condition.type === 'field'">
                                <Field :label="__('Field')" class="min-w-[10rem]">
                                    <Select v-model="condition.field" class="w-full" :read-only="locked" :options="vocabulary.fields.map(f => ({ value: f, label: f }))" />
                                </Field>
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :read-only="locked" :options="vocabulary.field_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Value')" class="flex-1 min-w-[8rem]">
                                    <Input v-model="condition.value" :read-only="locked" />
                                </Field>
                            </template>

                            <!-- custom field condition -->
                            <template v-else-if="condition.type === 'custom'">
                                <Field :label="__('Field')" class="min-w-[10rem]">
                                    <Select
                                        v-model="condition.field"
                                        class="w-full"
                                        :read-only="locked"
                                        :options="vocabulary.custom_fields.map(f => ({ value: f.handle, label: f.label }))"
                                        @update:model-value="onCustomFieldChange(condition)"
                                    />
                                </Field>
                                <Field :label="__('Operator')">
                                    <!-- Only the comparisons this type can answer. A date
                                         offering 'contains' or a yes/no offering 'greater
                                         than' lets somebody write a condition that can
                                         never be true, with nothing saying so. -->
                                    <Select
                                        v-model="condition.operator"
                                        :read-only="locked"
                                        :options="operatorsFor(condition.field).map(o => ({ value: o, label: o }))"
                                    />
                                </Field>
                                <Field
                                    v-if="!['is_set', 'is_empty', 'is_true', 'is_false'].includes(condition.operator)"
                                    :label="__('Value')"
                                    class="flex-1 min-w-[8rem]"
                                >
                                    <Select
                                        v-if="optionsFor(condition.field).length"
                                        v-model="condition.value"
                                        class="w-full"
                                        :read-only="locked"
                                        :options="optionsFor(condition.field).map(o => ({ value: o.value, label: o.label || o.value }))"
                                    />
                                    <Input v-else v-model="condition.value" :read-only="locked" />
                                </Field>
                            </template>

                            <!-- tag condition -->
                            <template v-else-if="condition.type === 'tag'">
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :read-only="locked" :options="vocabulary.tag_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Tag (id, slug or name)')" class="flex-1 min-w-[10rem]">
                                    <Input v-model="condition.value" :read-only="locked" />
                                </Field>
                            </template>

                            <!-- event condition -->
                            <template v-else-if="condition.type === 'event'">
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :read-only="locked" :options="vocabulary.event_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Event key')" class="flex-1 min-w-[8rem]">
                                    <Input v-model="condition.event" :read-only="locked" :placeholder="__('e.g. purchase')" />
                                </Field>
                                <Field :label="__('Within days (optional)')">
                                    <Input v-model.number="condition.within_days" :read-only="locked" type="number" min="0" />
                                </Field>
                            </template>

                            <!-- geo condition: „Postleitzahl im Umkreis von 30 km um 50667 (DE)" -->
                            <template v-else-if="condition.type === 'geo'">
                                <Field :label="__('Contact lives')" class="min-w-[12rem]">
                                    <Select v-model="condition.operator" class="w-full" :read-only="locked" :options="geoOperatorOptions" />
                                </Field>
                                <Field :label="__('Radius')" class="w-32">
                                    <Input v-model.number="condition.value" type="number" min="1" :read-only="locked" append="km" />
                                </Field>
                                <Field :label="__('around postal code')" class="w-40">
                                    <Input v-model="condition.plz" :read-only="locked" :placeholder="__('e.g. 50667')" />
                                </Field>
                                <Field :label="__('Country')" class="w-28">
                                    <Select v-model="condition.country" class="w-full" :read-only="locked" :options="countryOptions" />
                                </Field>
                            </template>

                            <!-- nested group: kept, not edited here -->
                            <template v-else-if="condition.type === 'group'">
                                <Text variant="subtle">
                                    {{ __(':count nested conditions (:match). Groups are kept as they are; edit them through the API.', { count: condition.conditions.length, match: condition.match === 'any' ? __('any condition') : __('all conditions') }) }}
                                </Text>
                            </template>
                        </div>

                        <template v-if="condition.type === 'geo'">
                            <Text v-if="placeFor(condition)" size="sm" variant="subtle" data-leadhub-geo-place>
                                {{ __('Centre: :plz :place', { plz: condition.plz, place: placeFor(condition) }) }}
                            </Text>
                            <Text v-else-if="placeFor(condition) === null" size="sm" variant="danger" data-leadhub-geo-unknown>
                                {{ __('The postal-code directory does not know :plz (:country). This condition matches nobody until it does. Check the code or run leadhub:postal-codes.', { plz: condition.plz, country: condition.country }) }}
                            </Text>
                        </template>
                    </div>

                    <div v-if="!locked" class="flex flex-wrap gap-2 pt-1">
                        <Button :text="__('Add field condition')" icon="plus" size="sm" variant="default" @click="addFieldCondition" />
                        <Button
                            v-if="vocabulary.custom_fields.length"
                            :text="__('Add custom field condition')"
                            icon="plus"
                            size="sm"
                            variant="default"
                            @click="addCustomCondition"
                        />
                        <Button :text="__('Add tag condition')" icon="plus" size="sm" variant="default" @click="addTagCondition" />
                        <Button :text="__('Add event condition')" icon="plus" size="sm" variant="default" @click="addEventCondition" />
                        <Button :text="__('Add postal code radius')" icon="plus" size="sm" variant="default" @click="addGeoCondition" />
                    </div>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('Matching contacts')">
            <Card>
                <div class="flex items-baseline gap-3 p-1" data-leadhub-preview-count>
                    <span class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ previewing ? '…' : (previewCount === null ? '—' : previewCount) }}
                    </span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('contacts currently match these rules') }}</span>
                </div>
                <Text v-if="withoutPostalCode" size="sm" variant="subtle" class="mt-2 px-1 block" data-leadhub-preview-no-postal-code>
                    {{ __(':count contacts have no postal code and never match a radius condition, inside or outside.', { count: withoutPostalCode }) }}
                </Text>
            </Card>
        </Panel>
    </div>
</template>
