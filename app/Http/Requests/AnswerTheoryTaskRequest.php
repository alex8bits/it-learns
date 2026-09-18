<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TheoryTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnswerTheoryTaskRequest extends FormRequest
{
    /**
     * Авторизация — middleware `auth:web` группы маршрута: POST-роут уже
     * закрыт от гостей. Отдельной политики нет — любой авторизованный
     * пользователь может отвечать на опубликованные задачи; guard'ы
     * публикации задачи/урока/курса живут в AnswerTheoryTask (404).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `option_id` должен существовать и принадлежать именно задаче из
     * роута: вариант чужой задачи отбрасывается здесь (422) ещё до
     * Action-слоя.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var TheoryTask $task the route-model-bound {task} of the answer route */
        $task = $this->route('task');

        return [
            'option_id' => [
                'required',
                'integer',
                Rule::exists('theory_task_options', 'id')->where('theory_task_id', $task->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'option_id.required' => 'Выберите вариант ответа',
            'option_id.integer' => 'Вариант ответа должен быть числом',
            'option_id.exists' => 'Этот вариант не относится к текущему вопросу',
        ];
    }

    /**
     * Narrow the validated payload for the controller: the picked option
     * id only.
     *
     * @return array{option_id: int}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
