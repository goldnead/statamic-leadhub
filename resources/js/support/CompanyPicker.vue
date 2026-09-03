<script setup>
/**
 * Company picker for the "Linked companies" panel on the contact screen.
 *
 * Same shape as ContactPicker: the CP `<Combobox>` over a first page handed
 * down by the controller, with typing hitting the brand-scoped
 * `leadhub.companies.options` endpoint. A plain `<Select>` over every company
 * stops working on an install that imported a CRM.
 *
 * `ignore-filter` is on because the server does the filtering; leaving the
 * client filter on as well would hide freshly fetched results that do not
 * match the raw query string.
 */
import { ref, watch } from 'vue';
import { Combobox } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    /** [{ value, label }] — the first page, from the controller. */
    options: { type: Array, default: () => [] },
    /** GET endpoint answering { options: [{ value, label }] }. */
    searchUrl: { type: String, default: null },
    placeholder: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const items = ref([...(props.options || [])]);
// `null`, not `''`, for "nothing picked". An empty string counts as a
// selection to the CP Combobox: it renders an empty selected-option label
// where the placeholder belongs, so the box looks blank and broken.
const selected = ref(props.modelValue ? String(props.modelValue) : null);

watch(() => props.options, (value) => { items.value = [...(value || [])]; });
watch(() => props.modelValue, (value) => { selected.value = value ? String(value) : null; });

let timer = null;

function onSearch(query) {
    if (!props.searchUrl) return;

    clearTimeout(timer);
    timer = setTimeout(async () => {
        try {
            const url = `${props.searchUrl}?q=${encodeURIComponent(query || '')}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return;
            const body = await res.json();
            const fetched = body.options || [];
            // Keep the selected option in the list, or the box empties itself
            // the moment somebody types.
            const current = (props.options || []).find((o) => String(o.value) === selected.value);
            items.value = current && !fetched.some((o) => String(o.value) === selected.value)
                ? [current, ...fetched]
                : fetched;
        } catch (e) {
            // A failed lookup leaves the current list standing. Clearing it
            // would look like "no such company", which is a different claim.
        }
    }, 250);
}

function onSelect(value) {
    selected.value = value === null || value === undefined || value === '' ? null : String(value);
    emit('update:modelValue', selected.value ?? '');
}
</script>

<template>
    <Combobox
        :model-value="selected"
        :options="items"
        :placeholder="placeholder || __('leadhub::companies.link_placeholder')"
        searchable
        clearable
        ignore-filter
        adaptive-width
        data-leadhub-company-picker
        @search="onSearch"
        @update:model-value="onSelect"
    />
</template>
