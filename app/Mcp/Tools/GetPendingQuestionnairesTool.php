<?php

namespace App\Mcp\Tools;

use App\Models\QuestionnaireRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_pending_questionnaires')]
#[Description('Liste les bilans en attente de réponse, avec les questions restantes formulées pour être posées une par une à la voix.')]
class GetPendingQuestionnairesTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $runs = QuestionnaireRun::where('status', 'pending')
            ->with(['questionnaire', 'answers'])
            ->orderBy('due_date')
            ->get();

        if ($runs->isEmpty()) {
            return Response::text('Aucun bilan en attente.');
        }

        $lines = $runs->map(function (QuestionnaireRun $run): string {
            $answeredTexts = $run->answers->pluck('question_text')->all();

            $remaining = collect($run->questionnaire->questions)
                ->reject(fn (array $question): bool => in_array($question['text'], $answeredTexts, true))
                ->pluck('text')
                ->implode(' ; ');

            $remainingLine = $remaining !== ''
                ? "questions restantes : {$remaining}"
                : 'toutes les questions ont déjà une réponse';

            return "{$run->questionnaire->name} (échéance {$run->due_date->translatedFormat('d M')}) — {$remainingLine}.";
        })->implode("\n");

        return Response::text($lines);
    }
}
