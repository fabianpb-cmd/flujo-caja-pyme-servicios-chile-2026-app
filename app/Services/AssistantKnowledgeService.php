<?php

namespace App\Services;

class AssistantKnowledgeService
{
    public function select(string $question, array $context): array
    {
        $rules = collect(config('assistant_knowledge', []));
        $haystack = mb_strtolower(implode(' ', [$question, $context['resource'] ?? '', $context['focused_field'] ?? '', $context['route'] ?? '']));
        $global = $rules->where('module', 'global');
        $matched = $rules->reject(fn (array $rule) => $rule['module'] === 'global')
            ->map(function (array $rule) use ($haystack, $context): array {
                $score = ($rule['module'] === ($context['resource'] ?? null) ? 4 : 0);
                foreach ($rule['keywords'] ?? [] as $keyword) {
                    $score += str_contains($haystack, mb_strtolower($keyword)) ? 1 : 0;
                }
                $rule['_score'] = $score;
                return $rule;
            })->filter(fn (array $rule) => $rule['_score'] > 0)->sortByDesc('_score')->take(9);

        return $global->concat($matched)->unique('id')->take(12)->map(function (array $rule): array {
            unset($rule['_score']);
            return $rule;
        })->values()->all();
    }
}
