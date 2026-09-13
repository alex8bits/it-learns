<script setup>
import AdminLayout from '../../Layouts/AdminLayout.vue';

defineProps({
    counters: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AdminLayout>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Дашборд</h1>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Пользователей всего</p>
                <p class="text-3xl font-semibold text-gray-900 mt-2">{{ counters.users_total }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Администраторов</p>
                <p class="text-3xl font-semibold text-gray-900 mt-2">{{ counters.admins_total }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Заблокировано</p>
                <p class="text-3xl font-semibold text-gray-900 mt-2">{{ counters.users_blocked }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Премиум активно</p>
                <p class="text-3xl font-semibold text-gray-300 mt-2">—</p>
                <p class="text-xs text-gray-400 mt-1">Запланировано в Этапе 3</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Оплат за месяц</p>
                <p class="text-3xl font-semibold text-gray-300 mt-2">—</p>
                <p class="text-xs text-gray-400 mt-1">Запланировано в Этапе 3</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <p class="text-sm text-gray-500">Курсов опубликовано</p>
                <p class="text-3xl font-semibold text-gray-300 mt-2">—</p>
                <p class="text-xs text-gray-400 mt-1">Запланировано в Этапе 5+</p>
            </div>
        </div>
    </AdminLayout>
</template>
