<?php

namespace App\Http\Requests\Content;

use App\Models\Content\Faq;
use Illuminate\Validation\Rule;

class FaqRequest extends ContentRequest
{
    protected function model(): string
    {
        return Faq::class;
    }

    public function rules(): array
    {
        return [
            'question' => [$this->req(), 'string', 'max:300'],
            'answer' => [$this->req(), 'string'],
            'category' => [$this->req(), Rule::in(Faq::CATEGORIES)],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:40'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
