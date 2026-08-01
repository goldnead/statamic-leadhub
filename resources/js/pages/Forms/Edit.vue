<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { PublishForm } from '@statamic/cms/ui';

defineProps([
    'title',           // form title
    'formHandle',      // string
    'blueprint',       // toPublishArray()
    'values',          // initial values
    'meta',            // initial meta
    'submitUrl',       // PATCH endpoint
    'indexUrl',        // back to list
]);
</script>

<template>
    <Head :title="[title, __('Form Mappings'), __('LeadHub')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <div class="mb-2">
            <Link :href="indexUrl" class="text-xs text-gray-500 hover:underline">
                ← {{ __('Form Mappings') }}
            </Link>
        </div>

        <PublishForm
            icon="forms"
            :title="title"
            :blueprint="blueprint"
            :initial-values="values"
            :initial-meta="meta"
            :submit-url="submitUrl"
            remember-tab
        />
    </div>
</template>
