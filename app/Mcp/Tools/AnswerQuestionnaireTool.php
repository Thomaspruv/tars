<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Support\FuzzyMatcher;
use App\Support\Questionnaire\QuestionAnswerNormalizer;
use App\Support\Questionnaire\QuestionnaireCompleter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('answer_questionnaire')]
#[Description("Répond à une ou plusieurs questions d'un bilan en attente. Le remplissage partiel est accepté — plusieurs appels peuvent compléter le même bilan au fil de plusieurs conversations. Le bilan est marqué complété seulement quand toutes ses questions ont une réponse.")]
class AnswerQuestionnaireTool extends LoggedTool
{
    public function __construct(
        private readonly QuestionnaireCompleter $completer,
        private readonly QuestionAnswerNormalizer $normalizer = new QuestionAnswerNormalizer,
        private readonly FuzzyMatcher $matcher = new FuzzyMatcher,
        private readonly NameResolver $resolver = new NameResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'questionnaire' => $schema->string()->description('Le nom approximatif du questionnaire (ex. "bilan mensuel").')->required(),
            'answers' => $schema->array()->items(
                $schema->object([
                    'question' => $schema->string()->description('Le texte exact de la question, tel que fourni par get_pending_questionnaires.')->required(),
                    'answer' => $schema->string()->description('La réponse donnée.')->required(),
                ])
            )->description('La liste des paires question/réponse.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'questionnaire' => ['required', 'string'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question' => ['required', 'string'],
            'answers.*.answer' => ['required', 'string'],
        ]);

        $questionnaire = $this->resolver->disambiguate(
            $this->matcher->rankedMatches(Questionnaire::where('is_active', true)->get(), $validated['questionnaire'], fn (Questionnaire $q): string => $q->name),
            fn (Questionnaire $q): string => $q->name,
            "Plusieurs questionnaires correspondent à « {$validated['questionnaire']} »",
        );

        if (! $questionnaire) {
            return Response::error("Aucun questionnaire ne correspond à « {$validated['questionnaire']} ».");
        }

        $run = $questionnaire->runs()->where('status', 'pending')->orderBy('due_date')->first();

        if (! $run) {
            return Response::error("Aucun bilan en attente pour « {$questionnaire->name} ».");
        }

        $accepted = 0;
        $rejected = [];

        foreach ($validated['answers'] as $pair) {
            $questionDef = $this->matcher->bestMatch(collect($questionnaire->questions), $pair['question'], fn (array $q): string => $q['text']);

            if (! $questionDef) {
                $rejected[] = $pair['question'];

                continue;
            }

            if ($this->storeAnswer($run, $questionDef, $pair['answer'])) {
                $accepted++;
            } else {
                $rejected[] = $questionDef['text'];
            }
        }

        $allAnswered = $questionnaire->questions !== []
            && collect($questionnaire->questions)->every(fn (array $question): bool => $run->answers()->where('question_text', $question['text'])->exists());

        if ($allAnswered) {
            $this->completer->complete($run);
        }

        $message = "{$accepted} réponse(s) enregistrée(s).";

        if ($rejected !== []) {
            $message .= ' Non prise(s) en compte : '.implode(', ', $rejected).'.';
        }

        if ($allAnswered) {
            $message .= ' Bilan complété.';
        }

        return Response::text($message);
    }

    /**
     * @param  array{text: string, type: string, scale_max?: int}  $questionDef
     */
    private function storeAnswer(QuestionnaireRun $run, array $questionDef, string $rawAnswer): bool
    {
        $normalized = $this->normalizer->normalize($questionDef, $rawAnswer);

        if ($normalized === null) {
            return false;
        }

        $run->answers()->updateOrCreate(
            ['question_text' => $questionDef['text']],
            ['type' => $questionDef['type'], 'answer_text' => $normalized['answerText'], 'answer_numeric' => $normalized['answerNumeric']],
        );

        return true;
    }
}
