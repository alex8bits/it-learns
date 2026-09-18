<script setup>
import Button from '../../Components/Button.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    course: { type: Object, required: true },
    // Прогресс текущего пользователя по этому курсу (только для
    // авторизованных): { percent, lessonStatuses: { [lesson_id]: 'InProgress'|'Completed' } }.
    progress: { type: Object, required: false, default: null },
    // Режим предпросмотра из админки (Этап 11): карточку видит админ
    // «глазами пользователя», включая черновики; все действия отключены,
    // ссылки на уроки ведут на read-only preview-роуты.
    previewMode: { type: Boolean, required: false, default: false },
});

// Карточка публичная: шапка зависит от гостя/авторизованного.
const page = usePage();
const authUser = computed(() => page.props.auth.user);

const lessonStatus = (lessonId) => props.progress?.lessonStatuses?.[lessonId] ?? null;

const startForm = useForm({});
const start = () => startForm.post(`/courses/${props.course.id}/start`);

// «Продолжить», если уже есть хоть какой-то прогресс, иначе «Начать курс».
const startLabel = computed(() => (props.progress && props.progress.percent > 0 ? 'Продолжить' : 'Начать курс'));
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
                <Link href="/" class="text-xl font-bold text-gray-900">it-learns</Link>
                <nav class="flex gap-3 text-sm">
                    <template v-if="authUser">
                        <Link href="/dashboard" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Кабинет
                        </Link>
                    </template>
                    <template v-else>
                        <Link href="/login" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Вход</Link>
                        <Link
                            href="/register"
                            class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50"
                        >
                            Регистрация
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-10">
            <div
                v-if="previewMode"
                class="mb-6 rounded bg-yellow-50 border border-yellow-300 px-4 py-3 text-sm text-yellow-800 flex flex-wrap items-center gap-2"
            >
                <span>
                    <span class="font-medium">Режим предпросмотра (глазами пользователя)</span>
                    — действия отключены, прогресс не записывается.
                </span>
                <span
                    v-if="course.status !== 'Published'"
                    class="px-2 py-0.5 bg-gray-100 text-gray-600 border border-gray-200 rounded text-xs"
                >
                    Курс не опубликован
                </span>
            </div>

            <Link href="/courses" class="text-sm text-blue-600 hover:underline">← Каталог курсов</Link>

            <section class="mt-4 bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                <div
                    class="h-56 bg-gradient-to-br from-blue-500 to-indigo-600 bg-cover bg-center"
                    :style="course.preview_image_url ? { backgroundImage: `url(${course.preview_image_url})` } : {}"
                />
                <div class="p-6">
                    <h1 class="text-3xl font-bold text-gray-900">{{ course.title }}</h1>
                    <p class="mt-3 text-gray-600">{{ course.description }}</p>

                    <div v-if="authUser && !previewMode" class="mt-5">
                        <Button type="button" :processing="startForm.processing" @click="start">
                            {{ startLabel }}
                        </Button>
                    </div>

                    <div v-if="authUser && progress && !previewMode" class="mt-4 flex items-center gap-3">
                        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div
                                class="h-full bg-blue-600 rounded-full"
                                :style="{ width: `${progress.percent}%` }"
                            />
                        </div>
                        <span class="text-xs text-gray-500 shrink-0">{{ progress.percent }}%</span>
                    </div>
                </div>
            </section>

            <section class="mt-8">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Уровни</h2>

                <div
                    v-for="level in course.levels"
                    :key="level.id"
                    class="bg-white rounded-lg shadow border border-gray-200 p-6 mb-4"
                >
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs">{{ level.title }}</span>
                    </div>

                    <ul v-if="level.lessons.length > 0" class="mt-4 space-y-2">
                        <li
                            v-for="lesson in level.lessons"
                            :key="lesson.id"
                            class="flex items-center gap-2 text-sm text-gray-700"
                        >
                            <span class="w-6 h-6 shrink-0 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full text-xs">
                                {{ lesson.order }}
                            </span>
                            <Link
                                v-if="authUser || previewMode"
                                :href="previewMode
                                    ? `/admin/courses/${course.id}/preview/lessons/${lesson.id}`
                                    : `/lessons/${lesson.slug}`"
                                class="text-blue-600 hover:underline"
                            >
                                {{ lesson.title }}
                            </Link>
                            <template v-else>{{ lesson.title }}</template>
                            <span
                                v-if="previewMode && lesson.is_published === false"
                                class="px-2 py-0.5 bg-gray-100 text-gray-600 border border-gray-200 rounded text-xs"
                            >
                                Черновик
                            </span>
                            <span
                                v-if="lessonStatus(lesson.id) === 'Completed'"
                                class="px-2 py-0.5 bg-green-50 text-green-700 border border-green-200 rounded text-xs"
                            >
                                Пройден
                            </span>
                            <span
                                v-else-if="lessonStatus(lesson.id) === 'InProgress'"
                                class="px-2 py-0.5 bg-yellow-50 text-yellow-700 border border-yellow-200 rounded text-xs"
                            >
                                В процессе
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-gray-400">Уроки появятся позже.</p>
                </div>

                <p v-if="course.levels.length === 0" class="text-sm text-gray-500">
                    Структура курса ещё не опубликована.
                </p>
            </section>
        </main>
    </div>
</template>
