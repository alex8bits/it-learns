<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ user: { type: Object, required: true } });

const roleForm = useForm({ role: props.user.roles[0]?.name ?? 'User' });

const submit = () => {
    roleForm.patch(`/admin/users/${props.user.id}`);
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/users/${user.id}`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К карточке
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">
            Редактирование {{ user.name }}
        </h1>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-md"
        >
            <label class="block mb-4">
                <span class="text-sm text-gray-700">Роль</span>
                <select
                    v-model="roleForm.role"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                >
                    <option value="User">Пользователь</option>
                    <option value="Admin">Администратор</option>
                </select>
            </label>
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
    </AdminLayout>
</template>
