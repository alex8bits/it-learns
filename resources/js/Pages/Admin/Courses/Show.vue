<script setup>
import Button from '../../../Components/Button.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    course: { type: Object, required: true },
    statuses: { type: Array, required: true },
    creator: { type: Object, default: null },
});

const page = usePage();

// Labels come from the backend option props — no enum duplicates in JS.
const labelFrom = (options, value) => options.find((o) => o.value === value)?.label ?? value;
const statusBadgeClass = (value) => ({
    Draft: 'bg-gray-100 text-gray-800',
    Published: 'bg-green-100 text-green-800',
    Archived: 'bg-yellow-100 text-yellow-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('ru-RU') : '—');

const deleteCourse = () => {
    if (confirm(`Удалить курс «${props.course.title}»? Уровни и уроки будут удалены вместе с ним.`)) {
        router.delete(`/admin/courses/${props.course.id}`);
    }
};

// --- Add level: a free-form title, order defaults to max + 1 (mirrors
// the backend default of `CreateLevel`), still editable ---
const nextOrder = () => {
    const orders = props.course.levels.map((level) => level.order);
    return (orders.length > 0 ? Math.max(...orders) : 0) + 1;
};

const addLevelForm = useForm({
    title: '',
    order: nextOrder(),
});

const submitAddLevel = () => {
    addLevelForm.post(`/admin/courses/${props.course.id}/levels`, {
        // After a successful add the redirect returns fresh props, but
        // the form keeps its values — clear the title and re-point the
        // order at the new max + 1.
        onSuccess: () => {
            addLevelForm.title = '';
            addLevelForm.order = nextOrder();
        },
    });
};

// --- Rename/reorder level: one inline form per level, one at a time ---
const editingLevelId = ref(null);
const editLevelForm = useForm({ title: '', order: null });

const startEditLevel = (level) => {
    editingLevelId.value = level.id;
    editLevelForm.title = level.title;
    editLevelForm.order = level.order;
};

const cancelEditLevel = () => {
    editingLevelId.value = null;
    editLevelForm.clearErrors();
};

const submitEditLevel = (level) => {
    editLevelForm.patch(`/admin/levels/${level.id}`, {
        onSuccess: () => {
            editingLevelId.value = null;
        },
    });
};

const deleteLevel = (level) => {
    if (confirm(`Удалить уровень «${level.title}»? Уроки уровня будут удалены вместе с ним.`)) {
        router.delete(`/admin/levels/${level.id}`);
    }
};

const deleteLesson = (lesson) => {
    if (confirm(`Удалить урок «${lesson.title}»?`)) {
        router.delete(`/admin/lessons/${lesson.id}`);
    }
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/courses" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <p
            v-if="page.props.status"
            class="mb-4 px-4 py-3 bg-green-50 text-green-800 rounded-md text-sm"
        >
            {{ page.props.status }}
        </p>

        <section class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-8">
            <div class="flex flex-col sm:flex-row">
                <img
                    v-if="course.preview_image_url"
                    :src="course.preview_image_url"
                    :alt="course.title"
                    class="sm:w-56 h-40 sm:h-auto object-cover"
                >
                <div
                    v-else
                    class="sm:w-56 h-40 sm:h-auto bg-gradient-to-br from-blue-500 to-indigo-600"
                />
                <div class="p-6 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span
                                class="px-2 py-1 rounded text-xs"
                                :class="statusBadgeClass(course.status)"
                            >
                                {{ labelFrom(statuses, course.status) }}
                            </span>
                            <h1 class="mt-2 text-2xl font-semibold text-gray-900">
                                {{ course.title }}
                            </h1>
                            <p class="text-sm text-gray-500">{{ course.slug }}</p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <!-- Предпросмотр «глазами пользователя» — новая
                                 вкладка: read-only страница без админ-обвязки. -->
                            <a
                                :href="`/admin/courses/${course.id}/preview`"
                                target="_blank"
                                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm"
                            >
                                Предпросмотр
                            </a>
                            <Link
                                :href="`/admin/courses/${course.id}/edit`"
                                class="px-4 py-2 bg-gray-200 text-gray-900 rounded hover:bg-gray-300 text-sm"
                            >
                                Редактировать
                            </Link>
                            <Link
                                :href="`/admin/courses/${course.id}/prompt`"
                                class="px-4 py-2 bg-gray-200 text-gray-900 rounded hover:bg-gray-300 text-sm"
                            >
                                Промпт курса
                            </Link>
                            <button
                                type="button"
                                class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm"
                                @click="deleteCourse"
                            >
                                Удалить
                            </button>
                        </div>
                    </div>
                    <p class="mt-3 text-gray-600">{{ course.description }}</p>
                    <p class="mt-2 text-sm text-gray-400">
                        Создан: {{ formatDate(course.created_at) }} · Автор: {{ creator?.name ?? '—' }}
                    </p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Уровни</h2>

            <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                <h3 class="font-semibold text-gray-900 mb-3">Добавить уровень</h3>
                <form
                    @submit.prevent="submitAddLevel"
                    class="flex flex-wrap items-start gap-4"
                >
                    <label class="block">
                        <span class="text-sm text-gray-700">Название</span>
                        <input
                            v-model="addLevelForm.title"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="например, Основы"
                            class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                        >
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">Порядок</span>
                        <input
                            v-model="addLevelForm.order"
                            type="number"
                            min="0"
                            class="mt-1 w-24 px-3 py-2 border border-gray-300 rounded-md"
                        >
                    </label>
                    <div class="pt-6">
                        <Button type="submit" :processing="addLevelForm.processing">Добавить</Button>
                    </div>
                </form>
                <div v-if="addLevelForm.errors.title" class="mt-2 text-sm text-red-600">
                    {{ addLevelForm.errors.title }}
                </div>
                <div v-if="addLevelForm.errors.order" class="mt-2 text-sm text-red-600">
                    {{ addLevelForm.errors.order }}
                </div>
            </div>

            <div
                v-for="level in course.levels"
                :key="level.id"
                class="bg-white rounded-lg border border-gray-200 p-6 mb-4"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs">
                            {{ level.title }}
                        </span>
                        <span class="text-xs text-gray-400">порядок: {{ level.order }}</span>
                    </div>
                    <div class="flex gap-2">
                        <Link
                            :href="`/admin/levels/${level.id}/lessons/create`"
                            class="px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
                        >
                            Добавить урок
                        </Link>
                        <button
                            type="button"
                            class="px-3 py-1.5 bg-gray-200 text-gray-900 rounded hover:bg-gray-300 text-sm"
                            @click="startEditLevel(level)"
                        >
                            Изменить
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1.5 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm"
                            @click="deleteLevel(level)"
                        >
                            Удалить
                        </button>
                    </div>
                </div>

                <form
                    v-if="editingLevelId === level.id"
                    @submit.prevent="submitEditLevel(level)"
                    class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-md flex flex-wrap items-start gap-4"
                >
                    <label class="block">
                        <span class="text-sm text-gray-700">Название</span>
                        <input
                            v-model="editLevelForm.title"
                            type="text"
                            maxlength="255"
                            required
                            class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                        >
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">Порядок</span>
                        <input
                            v-model="editLevelForm.order"
                            type="number"
                            min="0"
                            class="mt-1 w-24 px-3 py-2 border border-gray-300 rounded-md"
                        >
                    </label>
                    <div class="flex gap-2 pt-6">
                        <Button type="submit" :processing="editLevelForm.processing">Сохранить</Button>
                        <Button variant="secondary" type="button" @click="cancelEditLevel">
                            Отмена
                        </Button>
                    </div>
                    <div v-if="editLevelForm.errors.title" class="w-full text-sm text-red-600">
                        {{ editLevelForm.errors.title }}
                    </div>
                    <div v-if="editLevelForm.errors.order" class="w-full text-sm text-red-600">
                        {{ editLevelForm.errors.order }}
                    </div>
                </form>

                <ul v-if="level.lessons && level.lessons.length > 0" class="mt-4 space-y-2">
                    <li
                        v-for="lesson in level.lessons"
                        :key="lesson.id"
                        class="flex items-center gap-2 text-sm text-gray-700"
                    >
                        <span
                            class="w-6 h-6 shrink-0 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full text-xs"
                        >
                            {{ lesson.order }}
                        </span>
                        <span>{{ lesson.title }}</span>
                        <span
                            v-if="lesson.is_published"
                            class="px-2 py-0.5 bg-green-100 text-green-800 rounded text-xs"
                        >
                            Опубликован
                        </span>
                        <span v-else class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">
                            Черновик
                        </span>
                        <Link
                            :href="`/admin/lessons/${lesson.id}/edit`"
                            class="text-blue-600 hover:text-blue-800"
                        >
                            Редактировать
                        </Link>
                        <button
                            type="button"
                            class="text-red-600 hover:text-red-800"
                            @click="deleteLesson(lesson)"
                        >
                            Удалить
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-gray-400">В уровне пока нет уроков.</p>
            </div>

            <p v-if="course.levels.length === 0" class="text-sm text-gray-500">
                У курса пока нет уровней — добавьте первый выше.
            </p>
        </section>
    </AdminLayout>
</template>
