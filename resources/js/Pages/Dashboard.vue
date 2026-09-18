<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
                <Link href="/" class="text-xl font-bold text-gray-900">it-learns</Link>
                <div class="flex items-center gap-3 text-sm">
                    <Link
                        href="/courses"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50"
                    >
                        Каталог
                    </Link>
                    <Button variant="secondary" type="button" @click="logout">Выйти</Button>
                </div>
            </div>
        </header>
        <main class="max-w-6xl mx-auto px-4 py-10">
            <h1 class="text-3xl font-bold text-gray-900">Привет, {{ user.name }}!</h1>
            <p class="mt-2 text-sm text-gray-600">
                <Link href="/subscription" class="text-blue-600 hover:underline">Подписка</Link>
            </p>
            <div
                v-if="$page.props.auth?.user?.roles?.includes('Admin')"
                class="mt-4"
            >
                <Link href="/admin" class="text-blue-600 hover:underline">→ Админ-панель</Link>
            </div>

            <h2 class="mt-10 text-xl font-semibold text-gray-900">Каталог курсов</h2>
            <div v-if="courses.data.length > 0" class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <CourseCard
                    v-for="course in courses.data"
                    :key="course.id"
                    :course="course"
                    :percent="progressPercents[course.id] ?? null"
                />
            </div>
            <p v-else class="mt-4 text-center text-gray-500 py-12">Курсов пока нет — загляните позже.</p>

            <div class="mt-8 bg-white rounded-lg border border-gray-200">
                <Pagination :paginator="courses" />
            </div>
        </main>
    </div>
</template>

<script setup>
import Button from '../Components/Button.vue';
import CourseCard from '../Components/CourseCard.vue';
import Pagination from '../Components/Pagination.vue';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    user: { type: Object, required: true },
    courses: { type: Object, required: true },
    // Проценты прохождения курсов текущим пользователем:
    // { [course_id]: percent } — Inertia сериализует int-ключи как объект.
    progressPercents: { type: Object, default: () => ({}) },
});

const logout = () => router.post('/logout');
</script>
