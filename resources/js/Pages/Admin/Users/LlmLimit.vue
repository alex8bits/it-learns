<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, required: true },
    extraTokens: { type: Number, default: 0 },
    remainingToday: { type: Number, required: true },
});

const page = usePage();

const form = useForm({ extra_tokens: props.extraTokens });

const submit = () => {
    form.patch(`/admin/users/${props.user.id}/llm-limit`);
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/users/${user.id}`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К карточке
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">LLM-лимит {{ user.name }}</h1>
        <p
            v-if="page.props.status"
            class="mb-4 px-4 py-3 bg-green-50 text-green-800 rounded-md text-sm"
        >
            {{ page.props.status }}
        </p>
        <form @submit.prevent="submit" class="bg-white rounded-lg border border-gray-200 p-6 max-w-md">
            <label class="block mb-2">
                <span class="text-sm text-gray-700">Дополнительные токены</span>
                <input
                    v-model="form.extra_tokens"
                    type="number"
                    min="0"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                />
            </label>
            <p class="text-xs text-gray-500 mb-4">Токены поверх дневного лимита</p>
            <div v-if="form.errors.extra_tokens" class="mb-3 text-sm text-red-600">
                {{ form.errors.extra_tokens }}
            </div>
            <p class="text-sm text-gray-700 mb-4">Остаток на сегодня: {{ remainingToday }} токенов</p>
            <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 bg-gray-900 text-white rounded-md disabled:opacity-50"
            >
                Сохранить
            </button>
        </form>
    </AdminLayout>
</template>
