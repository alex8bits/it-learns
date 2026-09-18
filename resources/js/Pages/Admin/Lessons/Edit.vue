<script setup>
import Button from '../../../Components/Button.vue';
import Input from '../../../Components/Input.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    // The controller loads `lesson.level` for the authorize check, so the
    // back link can point at the parent course card.
    lesson: { type: Object, required: true },
});

const form = useForm({
    title: props.lesson.title,
    order: props.lesson.order,
    is_published: props.lesson.is_published,
    material: props.lesson.material ?? '',
});

const submit = () => {
    form.patch(`/admin/lessons/${props.lesson.id}`);
};

// Deleting a theory task cascades its options and the students' answers.
const deleteTheoryTask = (task) => {
    if (confirm('Удалить теоретическое задание? Варианты и ответы студентов будут удалены вместе с ним.')) {
        router.delete(`/admin/theory-tasks/${task.id}`);
    }
};

// Deleting a practice task cascades the students' submissions and AI feedback.
const deletePracticeTask = (task) => {
    if (confirm('Удалить практическое задание? Попытки студентов и ИИ-фидбэк будут удалены вместе с ним.')) {
        router.delete(`/admin/practice-tasks/${task.id}`);
    }
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/courses/${lesson.level.course_id}`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К курсу
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Редактирование урока</h1>
        <p class="text-sm text-gray-500 mb-6">
            Уровень: {{ lesson.level.title }}
        </p>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-3xl"
        >
            <Input
                v-model="form.title"
                label="Название"
                name="title"
                :error="form.errors.title"
                required
            />
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
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Материал</span>
                <textarea
                    v-model="form.material"
                    rows="14"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                    :class="form.errors.material ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.material" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.material }}
            </p>
            <div class="mt-4">
                <Button type="submit" :processing="form.processing">Сохранить</Button>
            </div>
        </form>

        <section class="mt-8 max-w-3xl">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Теоретические задания</h2>
                <Link
                    :href="`/admin/lessons/${lesson.id}/theory-tasks/create`"
                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
                >
                    Добавить задание
                </Link>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <ul v-if="lesson.theory_tasks && lesson.theory_tasks.length > 0" class="space-y-2">
                    <li
                        v-for="task in lesson.theory_tasks"
                        :key="task.id"
                        class="flex items-center gap-2 text-sm text-gray-700"
                    >
                        <span
                            class="w-6 h-6 shrink-0 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full text-xs"
                        >
                            {{ task.order }}
                        </span>
                        <span class="flex-1 truncate" :title="task.question">{{ task.question }}</span>
                        <span class="text-xs text-gray-400 shrink-0">
                            {{ task.options?.length ?? 0 }} вариантов
                        </span>
                        <span
                            v-if="task.is_published"
                            class="px-2 py-0.5 bg-green-100 text-green-800 rounded text-xs"
                        >
                            Опубликован
                        </span>
                        <span v-else class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">
                            Черновик
                        </span>
                        <Link
                            :href="`/admin/theory-tasks/${task.id}/edit`"
                            class="text-blue-600 hover:text-blue-800"
                        >
                            Редактировать
                        </Link>
                        <button
                            type="button"
                            class="text-red-600 hover:text-red-800"
                            @click="deleteTheoryTask(task)"
                        >
                            Удалить
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-400">В уроке пока нет теоретических заданий.</p>
            </div>
        </section>

        <section class="mt-8 max-w-3xl">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Практические задания</h2>
                <Link
                    :href="`/admin/lessons/${lesson.id}/practice-tasks/create`"
                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
                >
                    Добавить практическое задание
                </Link>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <ul v-if="lesson.practice_tasks && lesson.practice_tasks.length > 0" class="space-y-2">
                    <li
                        v-for="task in lesson.practice_tasks"
                        :key="task.id"
                        class="flex items-center gap-2 text-sm text-gray-700"
                    >
                        <span
                            class="w-6 h-6 shrink-0 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full text-xs"
                        >
                            {{ task.order }}
                        </span>
                        <span class="flex-1 truncate" :title="task.statement">{{ task.statement }}</span>
                        <span
                            v-if="task.is_published"
                            class="px-2 py-0.5 bg-green-100 text-green-800 rounded text-xs"
                        >
                            Опубликован
                        </span>
                        <span v-else class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">
                            Черновик
                        </span>
                        <Link
                            :href="`/admin/practice-tasks/${task.id}/edit`"
                            class="text-blue-600 hover:text-blue-800"
                        >
                            Редактировать
                        </Link>
                        <button
                            type="button"
                            class="text-red-600 hover:text-red-800"
                            @click="deletePracticeTask(task)"
                        >
                            Удалить
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-400">В уроке пока нет практических заданий.</p>
            </div>
        </section>
    </AdminLayout>
</template>
