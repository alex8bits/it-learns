<script setup>
import Button from '../../../Components/Button.vue';
import Input from '../../../Components/Input.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    // The controller loads `level.course` for the authorize check, so the
    // back link can point at the parent course card.
    level: { type: Object, required: true },
});

const form = useForm({
    title: '',
    order: '',
    is_published: false,
    material: '',
});

const submit = () => {
    // An empty order is dropped entirely so the backend appends the lesson
    // to the end of the level instead of casting an explicit null to 0.
    form.transform((data) => {
        const payload = { ...data };
        if (payload.order === '' || payload.order === null) {
            delete payload.order;
        }
        return payload;
    }).post(`/admin/levels/${props.level.id}/lessons`);
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/courses/${level.course.id}`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К курсу
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Новый урок</h1>
        <p class="text-sm text-gray-500 mb-6">
            Уровень: {{ level.title }}
        </p>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-3xl"
        >
            <Input
                v-model="form.title"
                label="Название"
                name="title"
                :error="form.errors.title"
                required
            />
            <Input
                v-model="form.order"
                label="Порядок"
                name="order"
                type="number"
                :error="form.errors.order"
            />
            <p class="-mt-2 mb-3 text-xs text-gray-500">
                Оставьте пустым — урок будет добавлен в конец уровня
            </p>
            <label class="flex items-center gap-2 mb-3">
                <input
                    v-model="form.is_published"
                    type="checkbox"
                    class="rounded border-gray-300"
                >
                <span class="text-sm text-gray-700">Опубликован</span>
            </label>
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Материал</span>
                <textarea
                    v-model="form.material"
                    rows="14"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                    :class="form.errors.material ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.material" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.material }}
            </p>
            <div class="mt-4">
                <Button type="submit" :processing="form.processing">Создать урок</Button>
            </div>
        </form>
    </AdminLayout>
</template>
