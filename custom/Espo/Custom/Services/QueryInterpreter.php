<?php

namespace Espo\Custom\Services;

class QueryInterpreter
{
    public function interpret(string $text): array
    {
        $text = strtolower($text);

        $filters = [];

        // 🔹 interest detection
        if (str_contains($text, 'japan')) {
            $filters['interest'] = 'Japan';
        }

        if (str_contains($text, 'blue')) {
            $filters['interest'] = 'Blue';
        }

        // 🔹 prospect detection
        if (str_contains($text, 'prospect') || str_contains($text, 'no purchase')) {
            $filters['noOrders'] = true;
        }

        // 🔹 stage detection
        if (str_contains($text, 'aware')) {
            $filters['status'] = 'Aware';
        }

        if (str_contains($text, 'considering')) {
            $filters['status'] = 'Considering';
        }

        // 🔹 high value
        if (str_contains($text, 'high value') || str_contains($text, 'best')) {
            $filters['minScore'] = 20;
        }

        return $filters;
    }
}
