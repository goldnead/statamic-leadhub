<script setup>
/**
 * The collected error box above a form.
 *
 * Every field renders its own message through `<Field :error="…">`. This
 * component exists for the rest: errors whose key is not a field on the screen
 * (a refused deletion, a rule that spans two inputs, a message Laravel put on
 * a nested key). Without it those come back as a 422 that changes nothing
 * visible — the "dead button" defect this QA run found most often.
 *
 * Same shape as statamic-marketing v1.5.3, deliberately: one pattern across
 * the addons rather than one per screen.
 */
import { computed } from 'vue';

const props = defineProps({
    /** The Inertia error bag: { field: 'message' }. */
    errors: { type: Object, default: () => ({}) },
    /** Keys already rendered at their own field, and therefore skipped here. */
    fields: { type: Array, default: () => [] },
});

const messages = computed(() =>
    Object.entries(props.errors || {})
        .filter(([key, message]) => message && !props.fields.includes(key))
        .map(([key, message]) => ({ key, message: Array.isArray(message) ? message.join(' ') : message }))
);
</script>

<template>
    <div
        v-if="messages.length"
        class="mb-4 rounded-lg bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300"
        data-leadhub-error-summary
        role="alert"
    >
        <p v-for="item in messages" :key="item.key">{{ item.message }}</p>
    </div>
</template>
