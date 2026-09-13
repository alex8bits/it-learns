<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-900">Дашборд</h1>
                <form method="POST" action="/logout">
                    <input type="hidden" name="_token" :value="csrf">
                    <button type="submit" class="px-3 py-1.5 text-sm bg-gray-200 hover:bg-gray-300 rounded">
                        Выйти
                    </button>
                </form>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 py-6">
            <p class="text-gray-700">Привет, {{ user.name }}! Email: {{ user.email }}</p>
            <p class="mt-2 text-sm text-gray-500">Каталог курсов и прогресс появятся в следующих этапах.</p>
            <div
                v-if="$page.props.auth.user.roles.includes('Admin')"
                class="mt-4"
            >
                <Link href="/admin" class="text-blue-600 hover:underline">→ Админ-панель</Link>
            </div>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, required: true },
    csrf: { type: String, required: true },
});
</script>
