<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const navItems = [
    { href: '/admin', label: 'Дашборд', match: (url) => url === '/admin' },
    { href: '/admin/users', label: 'Пользователи', match: (url) => url.startsWith('/admin/users') },
    { href: '/admin/payments', label: 'Оплаты', match: (url) => url.startsWith('/admin/payments') },
    { href: '/admin/courses', label: 'Курсы', match: (url) => url.startsWith('/admin/courses') },
    { href: '/admin/prompts', label: 'Промпты', match: (url) => url.startsWith('/admin/prompts') },
    { href: '/admin/audit-logs', label: 'Аудит-лог', match: (url) => url.startsWith('/admin/audit-logs') },
];

const isActive = (item) => item.match(page.url);
</script>

<template>
    <div class="min-h-screen flex bg-gray-50">
        <aside class="w-64 bg-white border-r border-gray-200 flex flex-col">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-lg font-semibold text-gray-900">Админ-панель</h1>
                <p class="text-sm text-gray-500 mt-1">{{ user?.name }}</p>
            </div>
            <nav class="flex-1 px-2 py-4 space-y-1">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    :class="[
                        'block px-3 py-2 rounded-md text-sm font-medium',
                        isActive(item)
                            ? 'bg-gray-900 text-white'
                            : 'text-gray-700 hover:bg-gray-100',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </nav>
            <div class="px-6 py-4 border-t border-gray-200">
                <Link href="/dashboard" class="text-sm text-gray-500 hover:text-gray-700">
                    ← На сайт
                </Link>
                <form method="POST" action="/logout" class="mt-2">
                    <input type="hidden" name="_token" :value="page.props.csrf">
                    <button type="submit" class="text-sm text-red-600 hover:text-red-800">
                        Выйти
                    </button>
                </form>
            </div>
        </aside>
        <main class="flex-1 p-8">
            <slot />
        </main>
    </div>
</template>
