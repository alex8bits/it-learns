<template>
    <div
        v-if="hasPages"
        class="flex items-center gap-1 px-4 py-3 border-t border-gray-200"
    >
        <button
            type="button"
            :disabled="!paginator.prev_page_url"
            class="px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="goTo(paginator.prev_page_url)"
        >
            ←
        </button>
        <button
            v-for="page in pages"
            :key="page.label"
            type="button"
            :disabled="!page.url"
            :class="[
                'px-3 py-1.5 text-sm rounded-md border',
                page.active
                    ? 'bg-gray-900 text-white border-gray-900'
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-100',
            ]"
            @click="goTo(page.url)"
        >
            {{ page.label }}
        </button>
        <button
            type="button"
            :disabled="!paginator.next_page_url"
            class="px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="goTo(paginator.next_page_url)"
        >
            →
        </button>
    </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    paginator: { type: Object, required: true },
});

// Laravel paginator elements: prev arrow, page numbers, next arrow.
// Ellipsis entries ("...") come with a null url and render disabled.
const pages = computed(() => (props.paginator.links ?? []).slice(1, -1));

const hasPages = computed(() => Number(props.paginator.last_page ?? 1) > 1);

const goTo = (url) => {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, replace: true });
};
</script>
