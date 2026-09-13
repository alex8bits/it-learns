<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ user: { type: Object, required: true } });
const page = usePage();
const canChange = computed(() => page.props.auth.user.id !== props.user.id);
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
