<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50">
        <form method="POST" action="/register" class="w-full max-w-sm bg-white p-6 rounded shadow" @submit="onSubmit">
            <input type="hidden" name="_token" :value="csrf">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Регистрация</h1>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Имя</span>
                <input type="text" name="name" required maxlength="255"
                       :class="[errors.name ? 'border-red-400' : 'border-gray-300', 'block w-full rounded border bg-gray-50 px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400']">
                <p v-if="errors.name" class="mt-1 text-xs text-red-600 break-words">{{ errMsg(errors.name) }}</p>
            </label>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Email</span>
                <input type="email" name="email" required
                       :class="[errors.email ? 'border-red-400' : 'border-gray-300', 'block w-full rounded border bg-gray-50 px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400']">
                <p v-if="errors.email" class="mt-1 text-xs text-red-600 break-words">{{ errMsg(errors.email) }}</p>
            </label>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Пароль</span>
                <div class="mt-1 flex gap-1">
                    <input :type="passwordType" name="password" required
                           :class="[errors.password ? 'border-red-400' : 'border-gray-300', 'block w-full rounded border bg-gray-50 px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400']">
                    <button type="button"
                            class="px-2 py-1.5 text-sm text-gray-600 border border-gray-300 bg-gray-50 rounded hover:bg-gray-100 select-none"
                            aria-label="Показать пароль"
                            @mousedown.prevent="showPasswords"
                            @mouseup="hidePasswords"
                            @mouseleave="hidePasswords"
                            @touchstart.prevent="showPasswords"
                            @touchend="hidePasswords"
                            @touchcancel="hidePasswords"
                            @blur="hidePasswords">👁</button>
                </div>
                <p v-if="errors.password" class="mt-1 text-xs text-red-600 break-words">{{ errMsg(errors.password) }}</p>
            </label>

            <label class="block mb-4">
                <span class="text-sm text-gray-700">Подтверждение пароля</span>
                <div class="mt-1 flex gap-1">
                    <input :type="passwordConfirmationType" name="password_confirmation" required
                           :class="[errors.password_confirmation ? 'border-red-400' : 'border-gray-300', 'block w-full rounded border bg-gray-50 px-2 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400']">
                    <button type="button"
                            class="px-2 py-1.5 text-sm text-gray-600 border border-gray-300 bg-gray-50 rounded hover:bg-gray-100 select-none"
                            aria-label="Показать пароль"
                            @mousedown.prevent="showPasswords"
                            @mouseup="hidePasswords"
                            @mouseleave="hidePasswords"
                            @touchstart.prevent="showPasswords"
                            @touchend="hidePasswords"
                            @touchcancel="hidePasswords"
                            @blur="hidePasswords">👁</button>
                </div>
                <p v-if="errors.password_confirmation" class="mt-1 text-xs text-red-600 break-words">{{ errMsg(errors.password_confirmation) }}</p>
            </label>

            <button type="submit" :disabled="submitting"
                    class="w-full px-4 py-2 bg-blue-600 text-white rounded disabled:opacity-50 disabled:cursor-not-allowed">
                <span v-if="submitting">Отправка...</span>
                <span v-else>Зарегистрироваться</span>
            </button>
        </form>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    csrf: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
});

const errMsg = (e) => Array.isArray(e) ? (e[0] || '') : (e || '');

const passwordType = ref('password');
const passwordConfirmationType = ref('password');

const showPasswords = () => {
    passwordType.value = 'text';
    passwordConfirmationType.value = 'text';
};

const hidePasswords = () => {
    passwordType.value = 'password';
    passwordConfirmationType.value = 'password';
};

const submitting = ref(false);

const onSubmit = (e) => {
    submitting.value = true;
    // Defer to the browser's native form submission so the redirect works.
    // Use rAF to ensure the disabled state has rendered first.
    requestAnimationFrame(() => e.target.submit());
};

// Re-enable the button when validation errors come back from the server.
watch(() => props.errors, (newErrors) => {
    if (newErrors && Object.keys(newErrors).length > 0) {
        submitting.value = false;
    }
}, { deep: true });
</script>
