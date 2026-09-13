<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50">
        <form method="POST" action="/reset-password" class="w-full max-w-sm bg-white p-6 rounded shadow">
            <input type="hidden" name="_token" :value="csrf">
            <input type="hidden" name="token" :value="token">

            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Новый пароль</h1>

            <div v-for="(msgs, field) in errors" :key="field"
                 class="mb-3 p-2 bg-red-50 text-red-700 text-sm rounded">
                <div v-for="msg in msgs" :key="msg">{{ msg }}</div>
            </div>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Email</span>
                <input type="email" name="email" :value="email" required
                       class="mt-1 block w-full rounded border-gray-300 px-2 py-1.5">
            </label>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Новый пароль</span>
                <input type="password" name="password" required
                       class="mt-1 block w-full rounded border-gray-300 px-2 py-1.5">
            </label>

            <label class="block mb-4">
                <span class="text-sm text-gray-700">Подтверждение</span>
                <input type="password" name="password_confirmation" required
                       class="mt-1 block w-full rounded border-gray-300 px-2 py-1.5">
            </label>

            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded">
                Сохранить пароль
            </button>
        </form>
    </div>
</template>

<script setup>
defineProps({
    csrf: { type: String, required: true },
    token: { type: String, required: true },
    email: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const errMsg = (e) => Array.isArray(e) ? (e[0] || '') : (e || '');
</script>
