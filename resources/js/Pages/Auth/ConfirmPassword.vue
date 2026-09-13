<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50">
        <form method="POST" action="/user/confirm-password" class="w-full max-w-sm bg-white p-6 rounded shadow">
            <input type="hidden" name="_token" :value="csrf">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Подтверждение пароля</h1>

            <div v-if="errors.password" class="mb-3 p-2 bg-red-50 text-red-700 text-sm rounded">
                {{ errors.password }}
            </div>

            <label class="block mb-4">
                <span class="text-sm text-gray-700">Пароль</span>
                <input type="password" name="password" required autofocus
                       class="mt-1 block w-full rounded border-gray-300 px-2 py-1.5">
            </label>

            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded">
                Подтвердить
            </button>
        </form>
    </div>
</template>

<script setup>
defineProps({
    csrf: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
});

const errMsg = (e) => Array.isArray(e) ? (e[0] || '') : (e || '');
</script>
