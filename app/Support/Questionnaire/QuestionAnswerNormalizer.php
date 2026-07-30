<?php

namespace App\Support\Questionnaire;

use App\Enums\QuestionType;

class QuestionAnswerNormalizer
{
    /**
     * The real ceiling of the answer_numeric column (decimal(8,2)) — a
     * 'number' answer beyond this would overflow on MySQL in production
     * even though SQLite (dev/tests) accepts it silently.
     */
    private const float MAX_NUMBER = 999999.99;

    /**
     * Validates and normalizes a raw answer against its question's type,
     * shared by every path that writes a QuestionnaireAnswer (the MCP
     * answer_questionnaire tool and the in-app fill-in form) so the two
     * can never diverge on what counts as a valid answer.
     *
     * @param  array{text: string, type: string, scale_max?: int}  $question
     * @return array{answerText: ?string, answerNumeric: ?float}|null null when $raw fails validation for the question's type
     */
    public function normalize(array $question, string $raw): ?array
    {
        $raw = trim($raw);

        return match ($question['type']) {
            QuestionType::Scale->value => $this->normalizeScale($question, $raw),
            QuestionType::Number->value => $this->normalizeNumber($raw),
            QuestionType::Boolean->value => $this->normalizeBoolean($raw),
            default => ['answerText' => $raw, 'answerNumeric' => null],
        };
    }

    /**
     * @param  array{text: string, type: string, scale_max?: int}  $question
     * @return array{answerText: ?string, answerNumeric: ?float}|null
     */
    private function normalizeScale(array $question, string $raw): ?array
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;
        $scaleMax = $question['scale_max'] ?? 10;

        if ($value < 1 || $value > $scaleMax) {
            return null;
        }

        return ['answerText' => null, 'answerNumeric' => $value];
    }

    /**
     * @return array{answerText: ?string, answerNumeric: ?float}|null
     */
    private function normalizeNumber(string $raw): ?array
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if (abs($value) > self::MAX_NUMBER) {
            return null;
        }

        return ['answerText' => null, 'answerNumeric' => $value];
    }

    /**
     * @return array{answerText: ?string, answerNumeric: ?float}|null
     */
    private function normalizeBoolean(string $raw): ?array
    {
        $normalized = mb_strtolower($raw);
        $isYes = in_array($normalized, ['oui', 'yes', 'true', '1'], true);
        $isNo = in_array($normalized, ['non', 'no', 'false', '0'], true);

        if (! $isYes && ! $isNo) {
            return null;
        }

        return ['answerText' => $isYes ? 'oui' : 'non', 'answerNumeric' => null];
    }
}
