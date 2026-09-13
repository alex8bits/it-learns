<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-900">Дашборд</h1>
                <Button variant="secondary" type="button" @click="logout">Выйти</Button>
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
import Button from '../Components/Button.vue';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    user: { type: Object, required: true },
});

const logout = () => router.post('/logout');
</script>
