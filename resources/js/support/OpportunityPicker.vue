<script setup>
/**
 * Opportunity picker for the task form.
 *
 * A plain `<Select>`, not the Combobox the contact picker uses: the list is
 * one contact's open deals, which is a handful, not thousands.
 *
 * The list depends on the contact, so it refetches whenever the contact
 * changes. Two consequences that are deliberate rather than accidental:
 *
 * - With no contact selected there are no options and the field is disabled.
 *   An enabled empty dropdown reads as "this contact has no deals", which is a
 *   different claim from "pick a contact first".
 * - Changing the contact clears the selection. Keeping it would leave a task
 *   pointing at the previous contact's deal, which the server rejects anyway.
 */
import { ref, computed, watch } from 'vue';
import { Select } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    /** Contact whose deals are on offer. Empty means no offer at all. */
    contactId: { type: [String, Number], default: '' },
    /** [{ value, label }] — the first list, from the controller. */
    options: { type: Array, default: () => [] },
    /** GET endpoint answering { options: [{ value, label }] }. */
    searchUrl: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const items = ref([...(props.options || [])]);
const selected = ref(props.modelValue ? String(props.modelValue) : '');
const loading = ref(false);

const hasContact = computed(() => !!String(props.contactId || '').trim());

const selectOptions = computed(() => [
    { value: '', label: __('No opportunity') },
    ...items.value,
]);

watch(() => props.options, (value) => { items.value = [...(value || [])]; });
watch(() => props.modelValue, (value) => { selected.value = value ? String(value) : ''; });

watch(() => props.contactId, async (contactId, previous) => {
    // Skip the initial settle: the controller already handed down the right
    // list, and refetching it would race the render for no gain.
    if (contactId === previous) return;

    if (!String(contactId || '').trim()) {
        items.value = [];
        onSelect('');

        return;
    }

    await load(contactId);

    // A deal that is not the new contact's cannot stay selected.
    if (selected.value && !items.value.some((o) => String(o.value) === selected.value)) {
        onSelect('');
    }
});

async function load(contactId) {
    if (!props.searchUrl) return;

    loading.value = true;

    try {
        const url = `${props.searchUrl}?contact=${encodeURIComponent(contactId)}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const body = await res.json();
        items.value = body.options || [];
    } catch (e) {
        // A failed lookup leaves the current list standing rather than
        // claiming the contact has no deals.
    } finally {
        loading.value = false;
    }
}

function onSelect(value) {
    selected.value = value === null || value === undefined ? '' : String(value);
    emit('update:modelValue', selected.value);
}
</script>

<template>
    <Select
        :model-value="selected"
        :options="selectOptions"
        :disabled="!hasContact || loading"
        :placeholder="hasContact ? __('No opportunity') : __('Pick a contact first')"
        data-leadhub-opportunity-picker
        @update:model-value="onSelect"
    />
</template>
