<script setup>
import Pagination from '../../../Components/Pagination.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { formatMoney } from '../../../utils/money';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    payments: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, required: true },
});

// Local input state: props.filters must not be mutated via v-model.
const status = ref(props.filters.status ?? '');
const email = ref(props.filters.email ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

const applyFilters = () => {
    router.get(
        '/admin/payments',
        {
            status: status.value || undefined,
            email: email.value.trim() || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

// Labels come from the backend `statuses` prop — no enum duplicates in JS.
const statusLabel = (value) => props.statuses.find((s) => s.value === value)?.label ?? value;

const badgeClass = (value) => ({
    Succeeded: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
    Refunded: 'bg-yellow-100 text-yellow-800',
    Pending: 'bg-gray-100 text-gray-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const openPayment = (id) => router.get(`/admin/payments/${id}`);
</script>

<template>
    <AdminLayout>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Оплаты</h1>
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="p-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
                <select
                    v-model="status"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                    @change="applyFilters"
                >
                    <option value="">Все статусы</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </option>
                </select>
                <input
                    v-model="email"
                    type="search"
                    placeholder="Поиск по email"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                    @keyup.enter="applyFilters"
                >
                <input
                    v-model="dateFrom"
                    type="date"
                    title="Дата от"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                    @change="applyFilters"
                >
                <input
                    v-model="dateTo"
                    type="date"
                    title="Дата до"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                    @change="applyFilters"
                >
                <button
                    type="button"
                    class="px-4 py-2 bg-gray-900 text-white rounded-md"
                    @click="applyFilters"
                >
                    Применить
                </button>
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Дата</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Пользователь</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Сумма</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Статус</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Провайдер</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">External ID</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in payments.data"
                        :key="payment.id"
                        class="border-t border-gray-200 cursor-pointer hover:bg-gray-50"
                        @click="openPayment(payment.id)"
                    >
                        <td class="px-4 py-2 text-sm">
                            {{ new Date(payment.created_at).toLocaleString('ru-RU') }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            {{ payment.user?.email ?? `#${payment.user_id}` }}
                        </td>
                        <td class="px-4 py-2 text-sm">{{ formatMoney(payment.amount, payment.currency) }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-1 rounded text-xs" :class="badgeClass(payment.status)">
                                {{ statusLabel(payment.status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm">{{ payment.provider }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            <span class="block max-w-[16rem] truncate" :title="payment.external_id">
                                {{ payment.external_id }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!payments.data || payments.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            Платежи не найдены
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="payments" />
        </div>
    </AdminLayout>
</template>
