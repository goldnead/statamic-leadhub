<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Models\Task;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'contact_id' => 'sometimes|nullable',
            'priority' => ['sometimes', 'nullable', 'in:'.implode(',', [
                Task::PRIORITY_LOW, Task::PRIORITY_NORMAL, Task::PRIORITY_HIGH,
            ])],
            'due_at' => 'sometimes|nullable|date',
            'assignee_id' => 'sometimes|nullable|string|max:255',
            'status' => ['sometimes', 'in:'.implode(',', [
                Task::STATUS_OPEN, Task::STATUS_DONE, Task::STATUS_CANCELLED,
            ])],
        ];
    }
}
