<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseProgressStatus;
use Database\Factories\UserCourseProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'course_id', 'status', 'current_lesson_id'])]
class UserCourseProgress extends Model
{
    /** @use HasFactory<UserCourseProgressFactory> */
    use HasFactory;

    /**
     * The user the progress row belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The course the progress row belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The lesson the user is currently on (a pointer, not ownership).
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function currentLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'current_lesson_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseProgressStatus::class,
        ];
    }
}
