<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 py-10">
        <div class="w-full max-w-4xl px-4">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Тарифы</h1>
                <p class="mt-2 text-gray-600">Выберите план обучения на it-learns</p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="bg-white p-6 rounded shadow flex flex-col">
                    <h2 class="text-xl font-semibold text-gray-900">Бесплатный</h2>
                    <p class="mt-3 text-3xl font-bold text-gray-900">0 ₽</p>
                    <ul class="mt-4 space-y-2 text-sm text-gray-700 flex-1">
                        <li>Каталог курсов и уроков</li>
                        <li>Теоретические материалы</li>
                        <li>Практические задания с проверкой</li>
                        <li>Прогресс обучения</li>
                    </ul>
                    <Link
                        :href="freeHref"
                        class="mt-6 inline-block text-center px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50"
                    >
                        {{ freeLabel }}
                    </Link>
                </div>

                <div class="bg-white p-6 rounded shadow border-2 border-blue-600 flex flex-col">
                    <h2 class="text-xl font-semibold text-gray-900">Премиум</h2>
                    <p class="mt-3 text-3xl font-bold text-gray-900">{{ price }}/мес</p>
                    <ul class="mt-4 space-y-2 text-sm text-gray-700 flex-1">
                        <li>Всё из бесплатного тарифа</li>
                        <li>ИИ-помощник во время прохождения курса</li>
                        <li>Фидбэк по ошибкам в практических заданиях</li>
                        <li>Генерация дополнительных задач</li>
                    </ul>
                    <Link
                        :href="premiumHref"
                        class="mt-6 inline-block text-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                    >
                        {{ premiumLabel }}
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatMoney } from '../utils/money';

const props = defineProps({
    premium: { type: Object, required: true },
});

// CTA зависит от гостя/авторизованного: покупка — только на /subscription.
const page = usePage();
const isGuest = computed(() => page.props.auth.user === null);

// Цена приходит в минорных единицах (99900 копеек = 999,00 ₽).
const price = computed(() => formatMoney(props.premium.amount_minor));

const freeHref = computed(() => (isGuest.value ? '/register' : '/dashboard'));
const freeLabel = computed(() => (isGuest.value ? 'Начать бесплатно' : 'Продолжить обучение'));
const premiumHref = computed(() => (isGuest.value ? '/login' : '/subscription'));
const premiumLabel = computed(() => (isGuest.value ? 'Войти' : 'Оформить премиум'));
</script>
