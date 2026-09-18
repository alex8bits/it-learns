<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { formatMoney } from '../../../utils/money';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    user: { type: Object, required: true },
    paymentStatuses: { type: Array, required: true },
    subscriptionStatuses: { type: Array, required: true },
    tiers: { type: Array, required: true },
});
const page = usePage();
const canChange = computed(() => page.props.auth?.user?.id !== props.user.id);
const canBlock = canChange;

const roleForm = useForm({ role: props.user.roles[0]?.name ?? 'User' });
const blockForm = useForm({});

const submitRole = () => {
    roleForm.patch(`/admin/users/${props.user.id}`);
};
const submitBlock = () => {
    blockForm.post(`/admin/users/${props.user.id}/block`);
};
const submitUnblock = () => {
    blockForm.post(`/admin/users/${props.user.id}/unblock`);
};

// Labels come from the backend option props — no enum duplicates in JS.
const labelFrom = (options, value) => options.find((o) => o.value === value)?.label ?? value;

const paymentBadgeClass = (value) => ({
    Succeeded: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
    Refunded: 'bg-yellow-100 text-yellow-800',
    Pending: 'bg-gray-100 text-gray-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('ru-RU') : '—');

const subscriptionPeriod = (subscription) => (subscription.starts_at || subscription.ends_at
    ? `${formatDate(subscription.starts_at)} — ${formatDate(subscription.ends_at)}`
    : '—');
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/users" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">{{ user.name }}</h1>
        <div class="bg-white rounded-lg border border-gray-200 p-6 space-y-4">
            <div>
                <p class="text-sm text-gray-500">Email</p>
                <p class="text-base text-gray-900">{{ user.email }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Текущая роль</p>
                <p class="text-base text-gray-900">{{ user.roles[0]?.name ?? '—' }}</p>
            </div>
            <div
                v-if="user.is_blocked"
                class="px-3 py-2 bg-red-50 text-red-800 rounded text-sm"
            >
                Пользователь заблокирован
            </div>
            <div v-if="canChange" class="pt-4 border-t border-gray-200">
                <Link
                    :href="`/admin/users/${user.id}/llm-limit`"
                    class="text-sm text-blue-600 hover:text-blue-800"
                >
                    LLM-лимит →
                </Link>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Подписки</h2>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Тариф</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Статус</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Период</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Провайдер</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="subscription in user.subscriptions"
                        :key="subscription.id"
                        class="border-t border-gray-200"
                    >
                        <td class="px-4 py-2 text-sm">{{ labelFrom(tiers, subscription.tier) }}</td>
                        <td class="px-4 py-2 text-sm">
                            {{ labelFrom(subscriptionStatuses, subscription.status) }}
                        </td>
                        <td class="px-4 py-2 text-sm">{{ subscriptionPeriod(subscription) }}</td>
                        <td class="px-4 py-2 text-sm">{{ subscription.provider ?? '—' }}</td>
                    </tr>
                    <tr v-if="!user.subscriptions || user.subscriptions.length === 0">
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                            Подписок нет
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Платежи</h2>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Дата</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Сумма</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in user.payments"
                        :key="payment.id"
                        class="border-t border-gray-200"
                    >
                        <td class="px-4 py-2 text-sm">{{ formatDateTime(payment.created_at) }}</td>
                        <td class="px-4 py-2 text-sm">{{ formatMoney(payment.amount, payment.currency) }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span
                                class="px-2 py-1 rounded text-xs"
                                :class="paymentBadgeClass(payment.status)"
                            >
                                {{ labelFrom(paymentStatuses, payment.status) }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!user.payments || user.payments.length === 0">
                        <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-500">
                            Платежей нет
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canChange"
            class="mt-6 bg-white rounded-lg border border-gray-200 p-6"
        >
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Сменить роль</h2>
            <form @submit.prevent="submitRole">
                <select
                    v-model="roleForm.role"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md mb-3"
                >
                    <option value="User">Пользователь</option>
                    <option value="Admin">Администратор</option>
                </select>
                <div v-if="roleForm.errors.role" class="mb-3 text-sm text-red-600">
                    {{ roleForm.errors.role }}
                </div>
                <button
                    type="submit"
                    :disabled="roleForm.processing"
                    class="px-4 py-2 bg-gray-900 text-white rounded-md disabled:opacity-50"
                >
                    Сохранить
                </button>
            </form>
        </div>

        <div
            v-if="canBlock"
            class="mt-6 bg-white rounded-lg border border-gray-200 p-6"
        >
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Блокировка</h2>
            <form v-if="!user.is_blocked" @submit.prevent="submitBlock">
                <button
                    type="submit"
                    :disabled="blockForm.processing"
                    class="px-4 py-2 bg-red-600 text-white rounded-md disabled:opacity-50"
                >
                    Заблокировать
                </button>
            </form>
            <form v-else @submit.prevent="submitUnblock">
                <button
                    type="submit"
                    :disabled="blockForm.processing"
                    class="px-4 py-2 bg-green-600 text-white rounded-md disabled:opacity-50"
                >
                    Разблокировать
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
