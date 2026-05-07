<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Note;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Database\Eloquent\Model;

/**
 * Build Eloquent model instances from raw arrays without persisting them.
 *
 * The flat-file repositories return real Eloquent model instances so the
 * controllers and views see the same shape as in eloquent-driver mode —
 * but without ever hitting the database. We force the model to look "saved"
 * (exists=true, syncOriginal()) and short-circuit save() / delete() at the
 * repository layer.
 */
class ModelHydrator
{
    public function contact(array $data): Contact
    {
        return $this->hydrate(new Contact(), $data);
    }

    public function event(array $data): Event
    {
        return $this->hydrate(new Event(), $data);
    }

    public function note(array $data): Note
    {
        return $this->hydrate(new Note(), $data);
    }

    public function tag(array $data): Tag
    {
        return $this->hydrate(new Tag(), $data);
    }

    public function followup(array $data): Followup
    {
        return $this->hydrate(new Followup(), $data);
    }

    public function formMapping(array $data): FormMapping
    {
        return $this->hydrate(new FormMapping(), $data);
    }

    /**
     * Hydrate a model from an array, mark it as if it had been freshly retrieved.
     *
     * Date strings are kept as ISO 8601 in the raw attribute map; Eloquent's
     * cast machinery converts them to Carbon on access, the same way it
     * does for database rows. We don't pre-parse here.
     */
    protected function hydrate(Model $model, array $data): Model
    {
        // Drop nested relation arrays that aren't real DB columns — they're
        // attached separately via setRelation() by the caller.
        unset($data['notes'], $data['events'], $data['followups'], $data['tags']);

        $model->setRawAttributes($data, sync: true);
        $model->exists = true;

        return $model;
    }
}
