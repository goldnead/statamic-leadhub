<script setup>
/**
 * LeadHub settings — a form, not a printout.
 *
 * Every control in the form is generated from `groups`, which the server built
 * from `Support\Settings`: the same definition the validation and the boot-time
 * config override read. That is deliberate. The read-only version this replaced
 * kept its own list of labels in JavaScript, so the screen was a second
 * description of `config/leadhub.php` that could disagree with it and never say
 * so.
 *
 * Saving writes only what changed and re-renders with the settings as they now
 * stand, which is not always what was typed: a number typed into a text box
 * comes back a number, a list comes back without the blank line the textarea
 * left behind, and a value set back to the shipped default comes back as that
 * default with the stored override deleted. The form takes the answer, so the
 * screen and the installation cannot drift.
 *
 * Two panels are deliberately still read-only. `statuses` is a map of handle to
 * label rather than a value — editing it means adding, renaming and removing
 * rows, and removing one strands every contact sitting on it. `environment` is
 * what the deployment owns: env-backed values, shown so they can be checked, not
 * offered so they can be half-changed by a database row the next deploy
 * overtakes.
 */
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Badge, Button, Field, Input, Textarea, Select, Switch,
} from '@statamic/cms/ui';

const props = defineProps([
    // Allow-listed slice of config('leadhub'): statuses, default_status, the two
    // behaviour booleans, timeline_payload_redaction, features and
    // exports.queue_threshold. Never the whole file — see SettingsController.
    // The shape is pinned by tests/Feature/SettingsSecretsTest.php.
    'config',
    'driver',           // active storage driver; also printed by the environment panel
    'publishCommand',   // 'php artisan vendor:publish --tag=leadhub-config'
    'groups',           // [{ title, description, fields: [{ key, type, label, description, ... }] }]
    'values',           // { '<dotted config path>': value }
    'updateUrl',        // PATCH endpoint that writes the form
    'canEdit',          // bool — permission: manage leadhub settings
    'environment',      // [{ label, value, env }] — deployment-owned, read-only
    'texts',            // page chrome, translated server-side
]);

/**
 * A `list` is edited as one textarea of lines, because that is how a set of
 * field names is read and pasted. The conversion lives here rather than on the
 * server so the server keeps receiving an array and nothing has to guess at a
 * separator.
 */
function toForm(values) {
    const form = {};

    for (const group of props.groups) {
        for (const field of group.fields) {
            const value = values[field.key];
            form[field.key] = field.type === 'list'
                ? (value ?? []).join('\n')
                : (value ?? (field.type === 'boolean' ? false : ''));
        }
    }

    return form;
}

function fromForm(form) {
    const out = {};

    for (const group of props.groups) {
        for (const field of group.fields) {
            const value = form[field.key];
            out[field.key] = field.type === 'list'
                ? String(value ?? '').split('\n').map((line) => line.trim()).filter(Boolean)
                : value;
        }
    }

    return out;
}

const form = ref(toForm(props.values));
const saved = ref(JSON.stringify(form.value));
const saving = ref(false);
const savedNotice = ref(false);
const fieldErrors = ref({});

const dirty = computed(() => JSON.stringify(form.value) !== saved.value);

// The save is an Inertia visit, so the answer arrives as new props on this same
// component instance. Without this the form would keep showing what was typed
// instead of what was stored — which is a different thing whenever a value went
// back to its default or a number arrived as a string.
watch(() => props.values, (values) => {
    form.value = toForm(values);
    saved.value = JSON.stringify(form.value);
    fieldErrors.value = {};
});

/**
 * Errors that belong to no control on this page. There should be none — every
 * validated key has a field here — but a rule added on the server and not here
 * would otherwise be rejected in silence.
 */
const generalErrors = computed(() => {
    const known = new Set(props.groups.flatMap((g) => g.fields.map((f) => `settings.${f.key}`)));

    return Object.entries(fieldErrors.value)
        .filter(([key]) => ! known.has(key))
        .map(([, messages]) => (Array.isArray(messages) ? messages[0] : messages));
});

function errorFor(field) {
    const messages = fieldErrors.value[`settings.${field.key}`];

    return Array.isArray(messages) ? messages[0] : messages;
}

function save() {
    if (! props.canEdit || saving.value) return;

    saving.value = true;
    savedNotice.value = false;
    fieldErrors.value = {};

    router.patch(props.updateUrl, { settings: fromForm(form.value) }, {
        preserveScroll: true,
        onSuccess: () => { savedNotice.value = true; },
        onError: (errors) => { fieldErrors.value = errors; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <Head :title="[__('Settings'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Settings')" icon="cog">
            <Button
                v-if="canEdit"
                variant="primary"
                :text="saving ? texts.saving : texts.save"
                :disabled="saving || ! dirty"
                data-settings-save
                @click="save"
            />
        </Header>

        <Alert v-if="! canEdit" variant="default" class="mb-4" data-settings-unavailable>
            {{ texts.unavailable }}
        </Alert>

        <!-- Inline, next to the form that produced it: the save has no page of
             its own to land on, and a confirmation the reader has to go looking
             for is a confirmation that did not arrive. -->
        <Alert v-if="savedNotice && ! dirty" variant="success" class="mb-4" data-settings-saved>
            {{ texts.saved }}
        </Alert>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-settings-form-errors>
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in generalErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Alert variant="default" class="mb-4">
            {{ texts.intro }}
            <code class="px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs">{{ publishCommand }}</code>
        </Alert>

        <div class="space-y-4">
            <Panel
                v-for="group in groups"
                :key="group.title"
                :heading="group.title"
            >
                <Card>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ group.description }}</p>

                    <!-- The marker sits on a wrapper, not on `Field`: Field
                         renders its own root and does not pass stray attributes
                         through, so the hook a test reaches for would not exist
                         in the DOM. -->
                    <div
                        v-for="field in group.fields"
                        :key="field.key"
                        class="mb-5 last:mb-0"
                        :data-settings-field="field.key"
                    >
                        <Field
                            :label="field.label"
                            :instructions="field.description"
                            :error="errorFor(field)"
                        >
                            <Switch
                                v-if="field.type === 'boolean'"
                                :model-value="form[field.key]"
                                :disabled="! canEdit"
                                @update:model-value="form[field.key] = $event"
                            />
                            <Select
                                v-else-if="field.type === 'select'"
                                :model-value="form[field.key]"
                                :options="field.options"
                                :disabled="! canEdit"
                                @update:model-value="form[field.key] = $event"
                            />
                            <Textarea
                                v-else-if="field.type === 'list'"
                                :model-value="form[field.key]"
                                :rows="6"
                                :disabled="! canEdit"
                                class="font-mono text-sm"
                                @update:model-value="form[field.key] = $event"
                            />
                            <Input
                                v-else
                                :model-value="form[field.key]"
                                :type="field.type === 'integer' ? 'number' : 'text'"
                                :input-attrs="field.min !== undefined ? { min: field.min } : {}"
                                :disabled="! canEdit"
                                :placeholder="field.nullable ? texts.defaultPlaceholder : ''"
                                @update:model-value="form[field.key] = $event"
                            />
                        </Field>
                    </div>
                </Card>
            </Panel>

            <!-- A map of handle to label, not a value. See the description. -->
            <Panel :heading="texts.statusesHeading">
                <Card>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ texts.statusesDescription }}</p>

                    <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="(label, key) in config.statuses"
                            :key="key"
                            class="flex items-center justify-between gap-4 py-3 first:pt-1 last:pb-1"
                        >
                            <dt class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ label }}</dt>
                            <dd>
                                <Badge
                                    v-if="key === values.default_status"
                                    color="blue"
                                    :text="key"
                                />
                                <code
                                    v-else
                                    class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono text-gray-600 dark:text-gray-300"
                                >{{ key }}</code>
                            </dd>
                        </div>
                    </dl>
                </Card>
            </Panel>

            <!-- Owned by the deployment: shown, never offered. -->
            <Panel :heading="texts.environmentHeading">
                <Card>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ texts.environmentDescription }}</p>

                    <div
                        v-for="entry in environment"
                        :key="entry.env + entry.label"
                        class="flex items-start justify-between gap-4 border-t border-gray-200 py-3 first:border-t-0 dark:border-gray-700"
                        :data-settings-environment="entry.env"
                    >
                        <div class="min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ entry.label }}</span>
                            <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ entry.env }}</span>
                        </div>
                        <span class="shrink-0 text-sm text-right text-gray-900 dark:text-gray-300">{{ entry.value }}</span>
                    </div>
                </Card>
            </Panel>
        </div>
    </div>
</template>
