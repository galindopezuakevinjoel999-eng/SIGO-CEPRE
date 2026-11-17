<?php

namespace App\Models\Content;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Pregunta frecuente (RF47). Colección "faqs" en MongoDB. También será la base
 * de conocimiento del chatbot (RF46–RF48): de ahí los keywords.
 *
 * @property string $question
 * @property string $answer
 * @property string $category  matricula | pagos | examen | horarios | general
 * @property list<string> $keywords
 * @property int $order
 * @property bool $active
 */
class Faq extends Model
{
    public const CATEGORIES = ['matricula', 'pagos', 'examen', 'horarios', 'general'];

    protected $connection = 'mongodb';

    protected $table = 'faqs';

    protected $fillable = ['question', 'answer', 'category', 'keywords', 'order', 'active'];

    protected $casts = [
        'order' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'category' => 'general',
        'keywords' => [],
        'order' => 0,
        'active' => true,
    ];
}
