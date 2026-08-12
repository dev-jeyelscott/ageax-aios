<?php

namespace App\Models;

use Database\Factories\ReviewFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['review_id', 'severity', 'location', 'current_implementation', 'expected_implementation', 'why_incorrect', 'required_fix', 'verification_requirement', 'implementation_fix_context'])]
class ReviewFinding extends Model
{
    /** @use HasFactory<ReviewFindingFactory> */
    use HasFactory;

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
