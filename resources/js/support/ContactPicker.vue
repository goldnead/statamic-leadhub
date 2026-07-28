<script setup>
/**
 * Contact picker for the task and opportunity forms.
 *
 * The addon has no contact-picker component and a plain `<Select>` over every
 * contact stops working somewhere in the low thousands. So: the CP
 * `<Combobox>` over a first page handed down by the controller, with typing
 * hitting the brand-scoped `leadhub.contacts.options` endpoint.
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
const selected = ref(props.modelValue ? String(props.modelValue) : '');

watch(() => props.options, (value) => { items.value = [...(value || [])]; });
watch(() => props.modelValue, (value) => { selected.value = value ? String(value) : ''; });

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
            // would look like "no such contact", which is a different claim.
        }
    }, 250);
}

function onSelect(value) {
    selected.value = value === null || value === undefined ? '' : String(value);
    emit('update:modelValue', selected.value);
}
</script>

<template>
    <Combobox
        :model-value="selected"
        :options="items"
        :placeholder="placeholder || __('Search contacts…')"
        searchable
        clearable
        ignore-filter
        data-leadhub-contact-picker
        @search="onSearch"
        @update:model-value="onSelect"
    />
</template>
