<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Events\LeadHubSubmissionAttached;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Services\SubmissionMapper;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Support\Facades\Log;
use Statamic\Events\SubmissionCreated;

class CreateOrUpdateLeadFromSubmission
{
    public function __construct(
        protected SubmissionMapper $mapper,
        protected ContactResolver $resolver,
        protected TimelineService $timeline,
        protected TagService $tags,
        protected FormMappingRepository $mappings,
    ) {
    }

    public function handle(SubmissionCreated $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            // Fail-safe: never break the Statamic form submission flow. (PRD §19.5)
            Log::warning('[LeadHub] Failed to process form submission', [
                'message' => $e->getMessage(),
                'submission' => method_exists($event->submission, 'id')
                    ? (string) $event->submission->id()
                    : null,
            ]);
        }
    }

    protected function process(SubmissionCreated $event): void
    {
        $submission = $event->submission;

        if (! $submission || ! method_exists($submission, 'form')) {
            return;
        }

        $form = $submission->form();

        if (! $form) {
            return;
        }

        $handle = $form->handle();
        $mapping = $this->mappings->findByHandle($handle);

        if (! $mapping || ! $mapping->isEnabled()) {
            return;
        }

        $data = method_exists($submission, 'data')
            ? (is_array($submission->data()) ? $submission->data() : $submission->data()->all())
            : [];

        $submissionId = method_exists($submission, 'id') ? (string) $submission->id() : null;

        $dto = $this->mapper->map($data, $mapping, $submissionId);

        if (! $dto->hasEmail() || ! EmailNormalizer::isValid($dto->email)) {
            Log::info('[LeadHub] Skipping submission without valid email', [
                'form_handle' => $handle,
                'submission_id' => $submissionId,
            ]);

            return;
        }

        [$contact, $wasCreated] = $this->resolver->resolveOrCreate($dto);

        $payload = $mapping->attach_full_submission
            ? $dto->rawSubmission
            : array_filter($dto->toContactAttributes());

        $this->timeline->recordSubmissionReceived(
            $contact,
            $handle,
            $payload,
            $submissionId,
        );

        if (! empty($dto->tags)) {
            $this->tags->attachMany($contact, $dto->tags, silent: true);
        }

        $this->mappings->markProcessed($mapping);

        event(new LeadHubSubmissionAttached(
            $contact,
            metadata: [
                'form_handle' => $handle,
                'submission_id' => $submissionId,
                'was_created' => $wasCreated,
            ],
        ));
    }
}
