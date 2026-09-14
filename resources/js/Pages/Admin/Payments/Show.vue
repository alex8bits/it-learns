<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { formatMoney } from '../../../utils/money';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    payment: { type: Object, required: true },
    statuses: { type: Array, required: true },
    subscriptionStatuses: { type: Array, required: true },
    tiers: { type: Array, required: true },
});

// Labels come from the backend option props — no enum duplicates in JS.
const labelFrom = (options, value) => options.find((o) => o.value === value)?.label ?? value;

const badgeClass = (value) => ({
    Succeeded: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
    Refunded: 'bg-yellow-100 text-yellow-800',
    Pending: 'bg-gray-100 text-gray-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('ru-RU') : '—');
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/payments" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Платёж #{{ payment.id }}</h1>

        <div class="bg-white rounded-lg border border-gray-200 p-6 space-y-4">
            <div>
                <p class="text-sm text-gray-500">Дата</p>
                <p class="text-base text-gray-900">{{ formatDateTime(payment.created_at) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Пользователь</p>
                <p class="text-base text-gray-900">
                    <Link
                        v-if="payment.user"
                        :href="`/admin/users/${payment.user_id}`"
                        class="text-blue-600 hover:underline"
                    >
                        {{ payment.user.email }}
                    </Link>
                    <span v-else>#{{ payment.user_id }}</span>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Сумма</p>
                <p class="text-base text-gray-900">{{ formatMoney(payment.amount, payment.currency) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Статус</p>
                <p class="text-base text-gray-900">
                    <span class="px-2 py-1 rounded text-xs" :class="badgeClass(payment.status)">
                        {{ labelFrom(statuses, payment.status) }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Провайдер</p>
                <p class="text-base text-gray-900">{{ payment.provider }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">External ID</p>
                <p class="text-base text-gray-900 break-all">{{ payment.external_id }}</p>
            </div>
        </div>

        <div
            v-if="payment.subscription"
            class="mt-6 bg-white rounded-lg border border-gray-200 p-6 space-y-4"
        >
            <h2 class="text-lg font-semibold text-gray-900">Связанная подписка</h2>
            <div>
                <p class="text-sm text-gray-500">Тариф</p>
                <p class="text-base text-gray-900">{{ labelFrom(tiers, payment.subscription.tier) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Статус</p>
                <p class="text-base text-gray-900">
                    {{ labelFrom(subscriptionStatuses, payment.subscription.status) }}
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Период</p>
                <p class="text-base text-gray-900">
                    {{ formatDate(payment.subscription.starts_at) }} —
                    {{ formatDate(payment.subscription.ends_at) }}
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Провайдер</p>
                <p class="text-base text-gray-900">{{ payment.subscription.provider ?? '—' }}</p>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Payload провайдера</h2>
            <pre
                v-if="payment.payload"
                class="bg-gray-50 rounded-md p-4 text-sm text-gray-800 overflow-x-auto"
            >{{ JSON.stringify(payment.payload, null, 2) }}</pre>
            <p v-else class="text-sm text-gray-500">—</p>
        </div>
    </AdminLayout>
</template>
