<template>
    <button :type="type" :disabled="isDisabled" :class="classes">
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

const isDisabled = computed(() => props.disabled || props.processing);

const variantClasses = {
    primary: 'bg-blue-600 text-white rounded',
    secondary: 'bg-gray-200 text-gray-900 rounded hover:bg-gray-300',
};

const classes = computed(() => [
    'px-4 py-2 disabled:opacity-50 disabled:cursor-not-allowed',
    variantClasses[props.variant] ?? variantClasses.primary,
]);
</script>
