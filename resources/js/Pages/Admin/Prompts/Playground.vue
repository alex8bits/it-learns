<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    courses: { type: Array, default: () => [] },
    result: { type: Object, default: null },
});

const page = usePage();

// Ошибка лимита ИИ приходит из page-level errors (глобальный renderable
// в bootstrap/app.php делает redirect back withErrors(['ai' => ...])),
// а не из flash — токены при отказе не списываются.
const aiError = computed(() => page.props.errors?.ai ?? null);

const form = useForm({
    course_id: null,
    user_message: '',
});

const submit = () => {
    form.post('/admin/prompts/playground/run');
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Playground промптов</h1>
            <Link href="/admin/prompts" class="text-sm text-blue-600 hover:text-blue-800">
                ← Глобальный промпт
            </Link>
        </div>

        <p
            v-if="aiError"
            class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
        >
            {{ aiError }}
        </p>

        <form @submit.prevent="submit" class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <label class="block mb-4">
                <span class="text-sm text-gray-700">Курс (для склейки с course-промптом)</span>
                <select
                    v-model="form.course_id"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                >
                    <option :value="null">Без курса (только глобальный промпт)</option>
                    <option v-for="course in courses" :key="course.id" :value="course.id">
                        {{ course.title }} ({{ course.status }})
                    </option>
                </select>
            </label>
            <div v-if="form.errors.course_id" class="mb-3 text-sm text-red-600">
                {{ form.errors.course_id }}
            </div>

            <label class="block mb-4">
                <span class="text-sm text-gray-700">Сообщение для теста</span>
                <textarea
                    v-model="form.user_message"
                    rows="6"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                ></textarea>
            </label>
            <div v-if="form.errors.user_message" class="mb-3 text-sm text-red-600">
                {{ form.errors.user_message }}
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 bg-gray-900 text-white rounded-md disabled:opacity-50"
            >
                Отправить
            </button>
        </form>

        <div v-if="result" class="bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Ответ модели</h2>
            <p class="text-sm text-gray-500 mb-4">
                токенов: {{ result.tokens_used }}, модель: {{ result.model }}
            </p>
            <pre class="whitespace-pre-wrap text-sm text-gray-900">{{ result.content }}</pre>
            <details class="mt-4">
                <summary class="text-sm text-blue-600 cursor-pointer">Использованный system-prompt</summary>
                <pre class="whitespace-pre-wrap mt-2 text-xs text-gray-600">{{ result.system_prompt }}</pre>
            </details>
        </div>
    </AdminLayout>
</template>
