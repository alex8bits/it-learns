<template>
    <GuestLayout>
        <div class="text-center">
            <h1 class="text-4xl font-bold text-gray-900">it-learns</h1>
            <p class="mt-2 text-gray-600">Онлайн-платформа обучения IT-навыкам</p>
            <div class="mt-6 flex gap-4 justify-center">
                <Link href="/login" class="px-4 py-2 bg-blue-600 text-white rounded">Войти</Link>
                <Link href="/register" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded">Регистрация</Link>
            </div>
        </div>
    </GuestLayout>
</template>

<script setup>
import GuestLayout from '../Layouts/GuestLayout.vue';
import { Link } from '@inertiajs/vue3';
</script>
