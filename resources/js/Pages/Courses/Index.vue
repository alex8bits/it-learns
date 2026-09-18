<script setup>
import CourseCard from '../../Components/CourseCard.vue';
import Pagination from '../../Components/Pagination.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    courses: { type: Object, required: true },
});

// Каталог публичный: шапка зависит от гостя/авторизованного.
const page = usePage();
const authUser = computed(() => page.props.auth.user);
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

        <main class="max-w-6xl mx-auto px-4 py-10">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Каталог курсов</h1>
                <p class="mt-2 text-gray-600">Онлайн-платформа обучения IT-навыкам</p>
            </div>

            <div v-if="courses.data.length > 0" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <CourseCard v-for="course in courses.data" :key="course.id" :course="course" />
            </div>

            <p v-else class="text-center text-gray-500 py-16">Курсов пока нет — загляните позже.</p>

            <div class="mt-8 bg-white rounded-lg border border-gray-200">
                <Pagination :paginator="courses" />
            </div>
        </main>
    </div>
</template>
