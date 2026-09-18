<script setup>
import Button from '../../../Components/Button.vue';
import Input from '../../../Components/Input.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    // The controller loads `options` and `lesson.level`; the task edit
    // form submits the whole option set (replace semantics).
    task: { type: Object, required: true },
});

const form = useForm({
    question: props.task.question,
    order: props.task.order,
    is_published: props.task.is_published,
    options: props.task.options.map((option) => ({
        text: option.text,
        is_correct: option.is_correct,
        error_text: option.error_text ?? '',
    })),
});

const addOption = () => {
    form.options.push({ text: '', is_correct: false, error_text: '' });
};

// Keep at least two options — the backend rejects anything smaller.
const removeOption = (index) => {
    if (form.options.length > 2) {
        form.options.splice(index, 1);
    }
};

// Radio semantics: picking an option marks it correct and every other
// option incorrect (exactly one correct is a backend invariant).
const markCorrect = (index) => {
    form.options.forEach((option, i) => {
        option.is_correct = i === index;
    });
};

const submit = () => {
    // An empty order is dropped entirely so the backend keeps the current
    // order instead of casting an explicit null to 0.
    form.transform((data) => {
        const payload = { ...data };
        if (payload.order === '' || payload.order === null) {
            delete payload.order;
        }
        return payload;
    }).patch(`/admin/theory-tasks/${props.task.id}`);
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/lessons/${task.lesson.id}/edit`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К уроку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Редактирование теоретического задания</h1>
        <p class="text-sm text-gray-500 mb-6">Урок: {{ task.lesson.title }}</p>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-3xl"
        >
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Вопрос</span>
                <textarea
                    v-model="form.question"
                    rows="4"
                    maxlength="65535"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                    :class="form.errors.question ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.question" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.question }}
            </p>

            <Input
                v-model="form.order"
                label="Порядок"
                name="order"
                type="number"
                :error="form.errors.order"
            />
            <label class="flex items-center gap-2 mb-3">
                <input
                    v-model="form.is_published"
                    type="checkbox"
                    class="rounded border-gray-300"
                >
                <span class="text-sm text-gray-700">Опубликован</span>
            </label>

            <h2 class="font-semibold text-gray-900 mt-6 mb-3">Варианты ответа</h2>
            <p v-if="form.errors.options" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.options }}
            </p>

            <div
                v-for="(option, index) in form.options"
                :key="index"
                class="mb-4 p-4 border border-gray-200 rounded-md"
            >
                <label class="flex items-center gap-2 mb-2">
                    <input
                        type="radio"
                        :name="`correct-option`"
                        :checked="option.is_correct"
                        class="border-gray-300"
                        @change="markCorrect(index)"
                    >
                    <span class="text-sm text-gray-700">Верный вариант</span>
                </label>
                <label class="block mb-2">
                    <span class="text-sm text-gray-700">Текст варианта</span>
                    <input
                        v-model="option.text"
                        type="text"
                        maxlength="2000"
                        class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                        :class="form.errors[`options.${index}.text`] ? 'border-red-400' : ''"
                    >
                </label>
                <p v-if="form.errors[`options.${index}.text`]" class="mb-2 text-xs text-red-600 break-words">
                    {{ form.errors[`options.${index}.text`] }}
                </p>
                <label class="block mb-2">
                    <span class="text-sm text-gray-700">Пояснение для неверного варианта</span>
                    <textarea
                        v-model="option.error_text"
                        rows="2"
                        maxlength="2000"
                        class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                        :class="form.errors[`options.${index}.error_text`] ? 'border-red-400' : ''"
                    ></textarea>
                </label>
                <p v-if="form.errors[`options.${index}.error_text`]" class="mb-2 text-xs text-red-600 break-words">
                    {{ form.errors[`options.${index}.error_text`] }}
                </p>
                <button
                    v-if="form.options.length > 2"
                    type="button"
                    class="text-sm text-red-600 hover:text-red-800"
                    @click="removeOption(index)"
                >
                    Удалить вариант
                </button>
            </div>

            <button
                type="button"
                class="px-3 py-1.5 bg-gray-200 text-gray-900 rounded hover:bg-gray-300 text-sm mb-4"
                :disabled="form.options.length >= 10"
                @click="addOption"
            >
                + вариант
            </button>

            <div class="mt-4">
                <Button type="submit" :processing="form.processing">Сохранить</Button>
            </div>
        </form>
    </AdminLayout>
</template>
