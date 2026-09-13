<template>
    <label class="block mb-3">
        <span class="text-sm text-gray-700">{{ label }}</span>
        <div class="mt-1 flex gap-1">
            <input
                :type="type"
                :name="name"
                :value="modelValue"
                :class="inputClasses"
                :autocomplete="autocomplete || undefined"
                :maxlength="maxlength || undefined"
                :placeholder="placeholder || undefined"
                :required="required || undefined"
                :autofocus="autofocus || undefined"
                @input="$emit('update:modelValue', $event.target.value)"
            >
            <slot name="suffix" />
        </div>
        <p v-if="errorText" class="mt-1 text-xs text-red-600 break-words">{{ errorText }}</p>
    </label>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, default: null },
    type: { type: String, default: 'text' },
    modelValue: { type: [String, Number], default: '' },
    error: { type: [String, Array], default: null },
    autocomplete: { type: String, default: null },
    autofocus: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    maxlength: { type: [String, Number], default: null },
    placeholder: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const errorText = computed(() => {
    if (!props.error) {
        return '';
    }

    return Array.isArray(props.error) ? (props.error[0] || '') : props.error;
});

const inputClasses = computed(() => [
    errorText.value ? 'border-red-400' : 'border-gray-300',
    'w-full rounded border bg-gray-50 px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400',
    'focus:bg-white focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400',
]);
</script>
