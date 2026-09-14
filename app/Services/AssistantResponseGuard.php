<?php

namespace App\Services;

class AssistantResponseGuard
{
    private const STATUSES = ['DEFINED', 'CALCULATED', 'REQUIRED', 'NOT_DEFINED', 'ADMIN_REQUIRED'];

    public function guard(array $response, array $allowedSourceIds): array
    {
        $status = strtoupper((string) ($response['status'] ?? 'NOT_DEFINED'));
        $answer = trim(strip_tags((string) ($response['answer'] ?? '')));
        $sources = array_values(array_filter((array) ($response['source_ids'] ?? []), 'is_string'));
        $unsafeAction = preg_match('/\b(guard[eé]|actualic[eé]|cre[eé]|elimin[eé]|modifiqu[eé]|emit[ií])\b/ui', $answer) === 1;
        $inventedValue = preg_match('/\b(pon|ingresa|escribe|selecciona)\s+(?:\$?\s*\d[\d.,]*|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\b/ui', $answer) === 1;
        $validSources = $sources !== [] && count(array_diff($sources, $allowedSourceIds)) === 0;

        if (! in_array($status, self::STATUSES, true) || $unsafeAction || $inventedValue || (in_array($status, ['DEFINED', 'CALCULATED'], true) && ! $validSources)) {
            return $this->notDefined();
        }

        return [
            'status' => $status,
            'answer' => $answer !== '' ? $answer : 'No está definido con la información disponible.',
            'source_ids' => $sources,
            'required_information' => array_values((array) ($response['required_information'] ?? [])),
            'warnings' => array_values((array) ($response['warnings'] ?? [])),
        ];
    }

    public function notDefined(): array
    {
        return ['status' => 'NOT_DEFINED', 'answer' => 'No tengo una regla verificada suficiente para responder eso.', 'source_ids' => [], 'required_information' => [], 'warnings' => []];
    }
}
