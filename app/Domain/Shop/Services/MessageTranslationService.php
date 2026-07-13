<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use Illuminate\Support\Facades\Http;

class MessageTranslationService
{
    /**
     * Translate text to the target language using Google Translate's free endpoint.
     *
     * @return array{translated: string, detected_source: string}|null
     */
    public function translate(string $text, string $targetLang = 'en'): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        $response = Http::timeout(10)->get('https://translate.googleapis.com/translate_a/single', [
            'client' => 'gtx',
            'sl' => 'auto',
            'tl' => $targetLang,
            'dt' => 't',
            'q' => $text,
        ]);

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json();

        // Google returns [[["translated","original",null,null,confidence],...],...,[sourceLang]]
        $translatedSegments = data_get($data, '0');
        $sourceLang = data_get($data, '2', 'unknown');

        if (! is_array($translatedSegments)) {
            return null;
        }

        $translated = collect($translatedSegments)
            ->map(fn ($segment) => data_get($segment, '0', ''))
            ->implode('');

        if (trim($translated) === '' || $translated === $text) {
            return null;
        }

        return [
            'translated' => $translated,
            'detected_source' => $sourceLang,
        ];
    }
}
