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
            'opportunity_id' => 'sometimes|nullable',
            'status' => ['sometimes', 'in:'.implode(',', [
                Task::STATUS_OPEN, Task::STATUS_DONE, Task::STATUS_CANCELLED,
            ])],
        ];
    }

    /**
     * A PATCH need not resend every field. When the form does not post a
     * contact, the one already on the task is what the opportunity is checked
     * against — otherwise changing only the deal would fail as a mismatch
     * against a contact nobody submitted.
     */
    protected function resolvedContactId(): mixed
    {
        if ($this->has('contact_id')) {
            return $this->input('contact_id');
        }

        $task = Task::query()->find($this->route('task'));

        return $task?->contact_id;
    }
}
