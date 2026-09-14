<?php

namespace App\Services;

class AssistantKnowledgeService
{
    public function select(string $question, array $context): array
    {
        $rules = collect(config('assistant_knowledge', []));
        $haystack = mb_strtolower(implode(' ', [$question, $context['resource'] ?? '', $context['focused_field'] ?? '', $context['route'] ?? '']));
        $global = $rules->where('module', 'global');
        if (blank($context['resource'] ?? null) && blank($context['page_key'] ?? null)) {
            return $global->values()->all();
        }
        $matched = $rules->reject(fn (array $rule) => $rule['module'] === 'global')
            ->map(function (array $rule) use ($haystack, $context): array {
                $score = ($rule['module'] === ($context['resource'] ?? null) ? 6 : 0);
                $pageMatch = ($rule['page_key'] ?? null) !== null && ($rule['page_key'] ?? null) === ($context['page_key'] ?? null);
                $score += $pageMatch ? 7 : 0;
                $focusedMatch = false;
                if (filled($context['focused_field'] ?? null) && in_array($context['focused_field'], $rule['field_keys'] ?? [], true)) {
                    $score += 10;
                    $focusedMatch = true;
                }
                $keywordMatch = false;
                foreach ($rule['keywords'] ?? [] as $keyword) {
                    if (str_contains($haystack, mb_strtolower($keyword))) {
                        $score++;
                        $keywordMatch = true;
                    }
                }
                $rule['_score'] = $score;
                $rule['_matched_context'] = $pageMatch || $focusedMatch || $keywordMatch;
                return $rule;
            })->filter(fn (array $rule) => $rule['_score'] > 0 && $rule['_matched_context'])->sortByDesc('_score')->take(9);

        return $global->concat($matched)->unique('id')->take(12)->map(function (array $rule): array {
            unset($rule['_score']);
            unset($rule['_matched_context']);
            return $rule;
        })->values()->all();
    }
}
