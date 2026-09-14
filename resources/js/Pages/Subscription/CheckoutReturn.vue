<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50">
        <div class="text-center">
            <div
                class="inline-block w-10 h-10 border-4 border-gray-300 border-t-blue-600 rounded-full animate-spin"
                role="status"
                aria-label="Обработка платежа"
            />
            <p class="mt-4 text-gray-700">Обрабатываем платёж…</p>
            <p class="mt-1 text-sm text-gray-500">
                {{ activated ? 'Оплата подтверждена, активируем подписку…' : 'Проверяем статус оплаты…' }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineProps({
    activated: { type: Boolean, default: false },
});

// Даём серверу мгновение на проведение платежа, затем уводим на страницу статуса.
onMounted(() => {
    setTimeout(() => router.get('/subscription'), 1500);
});
</script>
