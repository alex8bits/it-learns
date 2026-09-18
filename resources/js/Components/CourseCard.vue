<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    course: { type: Object, required: true },
    // Процент прохождения курса текущим пользователем (передаётся
    // только с дашборда): null в гостевом каталоге — индикатор
    // не рендерится.
    percent: { type: Number, default: null },
});

// Русская плюрализация для бейджа количества уровней.
const levelWord = (count) => {
    const mod10 = count % 10;
    const mod100 = count % 100;

    if (mod10 === 1 && mod100 !== 11) {
        return 'уровень';
    }

    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return 'уровня';
    }

    return 'уровней';
};
</script>

<template>
    <Link
        :href="`/courses/${course.slug}`"
        class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden flex flex-col hover:shadow-md transition-shadow"
    >
        <div
            class="h-40 bg-gradient-to-br from-blue-500 to-indigo-600 bg-cover bg-center"
            :style="course.preview_image_url ? { backgroundImage: `url(${course.preview_image_url})` } : {}"
        />
        <div class="p-5 flex flex-col flex-1">
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-lg font-semibold text-gray-900">{{ course.title }}</h3>
                <span class="shrink-0 px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs whitespace-nowrap">
                    {{ course.levels_count }} {{ levelWord(course.levels_count) }}
                </span>
            </div>
            <p class="mt-2 text-sm text-gray-600 line-clamp-3">{{ course.description }}</p>
            <div v-if="percent !== null" class="mt-auto pt-3">
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div
                        class="h-full rounded-full"
                        :class="percent === 100 ? 'bg-green-500' : 'bg-blue-600'"
                        :style="{ width: `${percent}%` }"
                    />
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    {{ percent === 100 ? 'Пройден' : `Пройдено ${percent}%` }}
                </p>
            </div>
        </div>
    </Link>
</template>
