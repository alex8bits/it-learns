<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';

defineProps({ stage: { type: Number, required: true } });
</script>

<template>
    <AdminLayout>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Курсы</h1>
        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
            <p class="text-gray-500 text-lg">Раздел в разработке</p>
            <p class="text-gray-400 text-sm mt-2">Запланировано в Этапе {{ stage }}</p>
        </div>
    </AdminLayout>
</template>
