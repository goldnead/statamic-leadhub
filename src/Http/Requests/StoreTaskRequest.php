<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\NormalizesDatePickerValues;
use Goldnead\Leadhub\Http\Requests\Concerns\ResolvesCrmReferences;
use Goldnead\Leadhub\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    use NormalizesDatePickerValues;
    use ResolvesCrmReferences;

    public function authorize(): bool
    {
        return $this->user()?->can('manage leadhub tasks') ?? false;
    }

    /**
     * `due_at` comes off the CP `<DatePicker>`, which posts an
     * `@internationalized/date` DateValue object rather than a string. Fold it
     * into a datetime string before the `date` rule ever sees it — this is the
     * 422 that made follow-ups uncreatable in v1.4.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeDatePickerInput('due_at');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'contact_id' => 'nullable',
            'priority' => ['sometimes', 'nullable', 'in:'.implode(',', [
                Task::PRIORITY_LOW, Task::PRIORITY_NORMAL, Task::PRIORITY_HIGH,
            ])],
            'due_at' => 'nullable|date',
            'assignee_id' => 'nullable|string|max:255',
            'opportunity_id' => 'nullable',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $contactId = $this->input('contact_id');

            if (filled($contactId) && ! $this->contactExists($contactId)) {
                $validator->errors()->add('contact_id', __('leadhub::tasks.validation.contact_not_found'));
            }

            if (! $this->isAssignableUser($this->input('assignee_id'))) {
                $validator->errors()->add('assignee_id', __('leadhub::tasks.validation.assignee_not_assignable'));
            }

            $this->validateOpportunity($validator, $this->resolvedContactId());
        });
    }

    /**
     * The contact the opportunity has to belong to. On create that is simply
     * what the form posted; UpdateTaskRequest overrides it, because a PATCH
     * that only changes the deal does not resend the contact and would
     * otherwise be rejected for a mismatch against nothing.
     */
    protected function resolvedContactId(): mixed
    {
        return $this->input('contact_id');
    }

    /**
     * The deal a task hangs on, checked through the model so the brand scope
     * applies, and checked against the selected contact so the form cannot
     * park a task on somebody else's deal by posting a stray id.
     */
    protected function validateOpportunity($validator, mixed $contactId): void
    {
        $opportunityId = $this->input('opportunity_id');

        if (blank($opportunityId)) {
            return;
        }

        $opportunity = $this->findOpportunity($opportunityId);

        if (! $opportunity) {
            $validator->errors()->add('opportunity_id', __('leadhub::tasks.validation.opportunity_not_found'));

            return;
        }

        if (blank($contactId) || (string) $opportunity->contact_id !== (string) $contactId) {
            $validator->errors()->add('opportunity_id', __('leadhub::tasks.validation.opportunity_contact_mismatch'));
        }
    }
}
