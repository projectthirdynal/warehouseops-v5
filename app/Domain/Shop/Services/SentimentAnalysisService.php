<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Models\SiteSetting;

class SentimentAnalysisService
{
    private const POSITIVE_WORDS = [
        'salamat', 'thank', 'thanks', 'good', 'great', 'awesome', 'perfect',
        'love', 'happy', 'satisfied', 'nice', 'excellent', 'wonderful',
        'appreciate', 'recommend', 'fast', 'easy', 'helpful', 'best',
        'ok', 'okay', 'fine', 'cool', 'great', 'maganda', 'mabait',
        'sige', 'push', 'order', 'buy', 'purchase', 'yes', 'oo',
        'confirm', 'ship', 'deliver', 'padala', 'approve', 'interested',
    ];

    private const NEGATIVE_PHRASES = [
        'hindi na',
    ];

    private const NEGATIVE_WORDS = [
        'bad', 'terrible', 'awful', 'hate', 'angry', 'mad', 'disappointed',
        'slow', 'late', 'broken', 'defective', 'damage', 'wrong', 'error',
        'problem', 'issue', 'complaint', 'refund', 'return', 'cancel',
        'cancel', 'never', 'no', 'hindi', 'wag', 'ayaw',
        'scam', 'fake', 'poor', 'horrible', 'worst', 'useless',
        'frustrated', 'annoying', 'waste', 'overpriced', 'expensive',
        'delayed', 'missing', 'lost', 'undelivered', 'bukas',
    ];

    private const POSITIVE_WEIGHT = 1.0;

    private const NEGATIVE_WEIGHT = -1.0;

    public function analyze(string $text): array
    {
        $lower = mb_strtolower($text);
        $words = preg_split('/[\s,.!?;:()"\'\-\n\r]+/', $lower) ?: [];
        $words = array_filter($words, fn ($w) => $w !== '');

        $positiveHits = 0;
        $negativeHits = 0;
        $flaggedWords = [];

        foreach (self::NEGATIVE_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $negativeHits++;
                $flaggedWords[] = $phrase;
            }
        }

        foreach ($words as $word) {
            if (in_array($word, self::POSITIVE_WORDS, true)) {
                $positiveHits++;
            }
            if (in_array($word, self::NEGATIVE_WORDS, true)) {
                $negativeHits++;
                $flaggedWords[] = $word;
            }
        }

        $totalHits = $positiveHits + $negativeHits;

        if ($totalHits === 0) {
            return [
                'sentiment' => 'neutral',
                'score' => 0.0,
                'positive_hits' => 0,
                'negative_hits' => 0,
                'flagged_words' => [],
            ];
        }

        $score = (($positiveHits * self::POSITIVE_WEIGHT) + ($negativeHits * self::NEGATIVE_WEIGHT)) / $totalHits;

        $threshold = (float) (SiteSetting::get('sentiment_negative_threshold', -0.15));

        $sentiment = match (true) {
            $score > abs($threshold) => 'positive',
            $score < $threshold => 'negative',
            default => 'neutral',
        };

        return [
            'sentiment' => $sentiment,
            'score' => round($score, 4),
            'positive_hits' => $positiveHits,
            'negative_hits' => $negativeHits,
            'flagged_words' => array_unique($flaggedWords),
        ];
    }

    public function analyzeConversationText(array $messages): array
    {
        $combined = implode(' ', array_map(fn ($m) => $m['body'] ?? '', $messages));

        return $this->analyze($combined);
    }

    public function shouldFlag(array $analysis): bool
    {
        if ($analysis['sentiment'] !== 'negative') {
            return false;
        }

        $autoFlagEnabled = SiteSetting::get('sentiment_auto_flag_enabled', true);
        if (! $autoFlagEnabled) {
            return false;
        }

        $minNegativeHits = (int) SiteSetting::get('sentiment_min_negative_hits', 2);

        return $analysis['negative_hits'] >= $minNegativeHits;
    }

    public function getThreshold(): float
    {
        return (float) (SiteSetting::get('sentiment_negative_threshold', -0.15));
    }

    public function getMinNegativeHits(): int
    {
        return (int) SiteSetting::get('sentiment_min_negative_hits', 2);
    }

    public function isAutoFlagEnabled(): bool
    {
        return (bool) SiteSetting::get('sentiment_auto_flag_enabled', true);
    }
}
