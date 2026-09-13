<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    stage: { type: Number, required: true },
    courseId: { type: Number, required: true },
});
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/courses" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Курс #{{ courseId }}</h1>
        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
            <p class="text-gray-500 text-lg">Раздел в разработке</p>
            <p class="text-gray-400 text-sm mt-2">Запланировано в Этапе {{ stage }}</p>
        </div>
    </AdminLayout>
</template>
