<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une proposition du rail que j'ai écartée. Elle revient après `until` : le
 * rail n'a donc pas de « non » définitif, seulement un « pas maintenant ».
 *
 * @property int $id
 * @property string $key
 * @property Carbon $until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'until'])]
class ProposalDismissal extends Model
{
    protected function casts(): array
    {
        return [
            'until' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ProposalDismissal>  $query
     * @return Builder<ProposalDismissal>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('until', '>', now());
    }
}
