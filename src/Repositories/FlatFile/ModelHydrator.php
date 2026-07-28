<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Note;
use Goldnead\Leadhub\Models\Segment;
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

    public function segment(array $data): Segment
    {
        return $this->hydrate(new Segment(), $data);
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

        // These models are auto-incrementing integers under the eloquent
        // driver, so Eloquent adds an implicit `id => int` cast. The flat-file
        // driver puts a UUID in `id`, and that cast quietly turned it into an
        // integer: `(int) 'e3d35f29-…'` is 0, `(int) '58d2b561-…'` is 58.
        //
        // The consequence was not cosmetic. FlatFileEventRepository builds its
        // log path from `$contact->id`, so every contact whose UUID begins
        // with a hex letter — roughly two in five — wrote its timeline into
        // the same `events/0.jsonl` and read back everybody else's. Telling
        // the instance its key is a string is what removes the implicit cast.
        $model->setIncrementing(false);
        $model->setKeyType('string');

        $model->setRawAttributes($this->encodeJsonCasts($model, $data), sync: true);
        $model->exists = true;

        return $model;
    }

    /**
     * Re-encode values whose cast expects JSON.
     *
     * A database row hands Eloquent a JSON *string* and the cast decodes it.
     * Flat-file records arrive already decoded, so `payload` reached an
     * `array` cast as a PHP array and `json_decode()` was handed an array —
     * a TypeError, and a 500 on any contact detail page with a timeline entry
     * that carried a payload.
     *
     * Encoding here rather than special-casing the reader keeps the promise
     * this class makes: the raw attribute map looks like a database row, and
     * Eloquent's own cast machinery does the rest.
     */
    protected function encodeJsonCasts(Model $model, array $data): array
    {
        $jsonCasts = ['array', 'json', 'object', 'collection'];

        foreach ($model->getCasts() as $attribute => $cast) {
            if (! in_array($cast, $jsonCasts, true)) {
                continue;
            }

            if (array_key_exists($attribute, $data) && (is_array($data[$attribute]) || is_object($data[$attribute]))) {
                $data[$attribute] = json_encode($data[$attribute]);
            }
        }

        return $data;
    }
}
