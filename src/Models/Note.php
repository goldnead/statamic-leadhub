<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\Leadhub\Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Note extends Model
{
    use HasFactory;

    protected $table = 'leadhub_notes';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $note): void {
            if (empty($note->uuid)) {
                $note->uuid = (string) Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected static function newFactory(): NoteFactory
    {
        return NoteFactory::new();
    }
}
