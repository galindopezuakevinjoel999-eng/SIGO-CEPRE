<?php

namespace App\Http\Requests\Content;

use App\Models\Content\HomeBlock;
use Illuminate\Validation\Rule;

class HomeBlockRequest extends ContentRequest
{
    protected function model(): string
    {
        return HomeBlock::class;
    }

    public function rules(): array
    {
        return [
            'type' => [$this->req(), Rule::in(HomeBlock::TYPES)],
            'order' => ['sometimes', 'integer', 'min:0'],
            // Libre: cada tipo de bloque define su forma (ver ContentSeeder).
            'data' => [$this->req(), 'array'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
