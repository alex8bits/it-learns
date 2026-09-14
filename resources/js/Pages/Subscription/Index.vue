<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-900">Подписка</h1>
                <Button variant="secondary" type="button" @click="logout">Выйти</Button>
            </div>
        </header>
        <main class="max-w-3xl mx-auto px-4 py-6">
            <div v-if="status" class="mb-4 p-3 bg-green-50 text-green-700 text-sm rounded">
                {{ status }}
            </div>

            <div class="bg-white p-6 rounded shadow">
                <template v-if="isPremium">
                    <p class="text-green-700 font-semibold">Премиум-подписка активна</p>
                    <p v-if="expiresAt" class="mt-2 text-gray-700">
                        Действует до <span class="font-medium">{{ formattedExpiresAt }}</span>
                    </p>
                    <p v-if="isCancelled" class="mt-2 text-sm text-gray-500">
                        Подписка отменена и не будет продлеваться — доступ сохранится до конца оплаченного периода.
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        Премиум открывает ИИ-помощника во время прохождения курса.
                    </p>
                    <Button class="mt-4" variant="secondary" type="button" @click="cancel">
                        Отменить подписку
                    </Button>
                </template>

                <template v-else>
                    <p class="text-gray-700">Премиум-подписка не активна.</p>
                    <p v-if="isPending" class="mt-2 text-sm text-gray-500">
                        Последняя попытка оплаты ещё обрабатывается.
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        Премиум открывает ИИ-помощника во время прохождения курса:
                        фидбэк по ошибкам и генерацию дополнительных задач.
                    </p>
                    <Button class="mt-4" type="button" @click="checkout">
                        Оформить премиум
                    </Button>
                </template>

                <p class="mt-6 text-sm">
                    <Link href="/pricing" class="text-blue-600 hover:underline">Сравнить тарифы</Link>
                </p>
            </div>
        </main>
    </div>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    isPremium: { type: Boolean, default: false },
    expiresAt: { type: String, default: null },
    lastSubscription: { type: Object, default: null },
});

// Flash status от checkout/cancel приходит через shared props.
const page = usePage();
const status = computed(() => page.props.status);

// Values mirror App\Enums\SubscriptionStatus (PHP enum has no JS counterpart).
const SUBSCRIPTION_STATUS = { cancelled: 'Cancelled', pending: 'Pending' };

// Статус последней подписки: «Отменена» при действующем периоде — отдельная ветка отображения.
const isCancelled = computed(() => props.lastSubscription?.status === SUBSCRIPTION_STATUS.cancelled);
const isPending = computed(() => props.lastSubscription?.status === SUBSCRIPTION_STATUS.pending);

const formattedExpiresAt = computed(() =>
    props.expiresAt ? new Date(props.expiresAt).toLocaleDateString('ru-RU') : '',
);

const checkout = () => router.post('/subscription/checkout');
const cancel = () => router.post('/subscription/cancel');
const logout = () => router.post('/logout');
</script>
