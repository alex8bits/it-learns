<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({ prompt: { type: String, default: '' } });

const page = usePage();

const form = useForm({
    body: props.prompt,
    comment: '',
});

const submit = () => {
    form.patch('/admin/prompts/global');
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Глобальный системный промпт</h1>
            <div class="flex gap-4">
                <Link
                    href="/admin/prompts/playground"
                    class="text-sm text-blue-600 hover:text-blue-800"
                >
                    Playground →
                </Link>
                <Link
                    :href="`/admin/prompts/history?key=ai.global_system_prompt`"
                    class="text-sm text-blue-600 hover:text-blue-800"
                >
                    История версий →
                </Link>
            </div>
        </div>
        <p
            v-if="page.props.status"
            class="mb-4 px-4 py-3 bg-green-50 text-green-800 rounded-md text-sm"
        >
            {{ page.props.status }}
        </p>
        <form @submit.prevent="submit" class="bg-white rounded-lg border border-gray-200 p-6">
            <label class="block mb-4">
                <span class="text-sm text-gray-700">Текст промпта</span>
                <textarea
                    v-model="form.body"
                    rows="12"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                ></textarea>
            </label>
            <div v-if="form.errors.body" class="mb-3 text-sm text-red-600">
                {{ form.errors.body }}
            </div>
            <label class="block mb-4">
                <span class="text-sm text-gray-700">Комментарий к версии</span>
                <input
                    v-model="form.comment"
                    type="text"
                    maxlength="1000"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                />
            </label>
            <div v-if="form.errors.comment" class="mb-3 text-sm text-red-600">
                {{ form.errors.comment }}
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 bg-gray-900 text-white rounded-md disabled:opacity-50"
            >
                Сохранить (создаст новую версию)
            </button>
        </form>
    </AdminLayout>
</template>
