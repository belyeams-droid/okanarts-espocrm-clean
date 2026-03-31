<?php

namespace Espo\Custom\Services;

class SignalInterpreter
{
    protected array $dictionary;
    protected array $intentDictionary;

    public function __construct()
    {
        $this->dictionary = require 'custom/Espo/Custom/Resources/signalDictionary.php';
        $this->intentDictionary = require 'custom/Espo/Custom/Resources/intentDictionary.php';
    }

    public function interpret(string $input): array
    {
        $parts = $this->splitQuery($input);

        // INCLUDE
        $signals = $this->resolveHierarchy(
            $this->matchSignals($parts['include'])
        );

        $intent = $this->matchIntent($parts['include']);

        // EXCLUDE
        $excludeSignals = [];
        if (!empty($parts['exclude'])) {
            $excludeSignals = $this->resolveHierarchy(
                $this->matchSignals($parts['exclude'])
            );
        }

        $where = $this->buildWhereClause($signals, $intent, $excludeSignals);

        return [
            'signals' => array_keys($signals),
            'intent' => array_keys($intent),
            'exclude' => array_keys($excludeSignals),
            'where' => $where
        ];
    }

    /**
     * Split query into INCLUDE / EXCLUDE parts using NOT
     */
    protected function splitQuery(string $input): array
    {
        $parts = preg_split('/\bNOT\b/i', $input);

        return [
            'include' => trim($parts[0]),
            'exclude' => isset($parts[1]) ? trim($parts[1]) : null
        ];
    }

    protected function matchSignals(string $input): array
    {
        $input = strtolower($input);
        $matched = [];

        foreach ($this->dictionary as $key => $entry) {

            $terms = array_merge([$key], $entry['variants']);

            foreach ($terms as $term) {
                if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $input)) {
                    $matched[$key] = $entry;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * if kyoto exists → remove japan
     */
    protected function resolveHierarchy(array $signals): array
    {
        foreach ($signals as $key => $signal) {

            if (isset($signal['parent'])) {
                $parent = $signal['parent'];

                if (isset($signals[$parent])) {
                    unset($signals[$parent]);
                }
            }
        }

        return $signals;
    }

    protected function matchIntent(string $input): array
    {
        $input = strtolower($input);
        $matched = [];

        foreach ($this->intentDictionary as $key => $intent) {

            foreach ($intent['terms'] as $term) {

                if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $input)) {
                    $matched[$key] = $intent;
                    break;
                }
            }
        }

        return $matched;
    }

    protected function buildWhereClause(array $signals, array $intent, array $excludeSignals = []): string
    {
        $clauses = [];

        // INCLUDE SIGNALS
        foreach ($signals as $signal) {

            $conditions = [];

            foreach ($signal['variants'] as $variant) {
                $conditions[] = "relationship_narrative LIKE '%" . addslashes($variant) . "%'";
            }

            $clauses[] = '(' . implode(' OR ', $conditions) . ')';
        }

        // INTENT
        foreach ($intent as $intentItem) {
            $clauses[] = '(' . $intentItem['sql'] . ')';
        }

        // EXCLUDE SIGNALS
        foreach ($excludeSignals as $signal) {

            $conditions = [];

            foreach ($signal['variants'] as $variant) {
                $conditions[] = "relationship_narrative LIKE '%" . addslashes($variant) . "%'";
            }

            $clauses[] = 'NOT (' . implode(' OR ', $conditions) . ')';
        }

        return implode(' AND ', $clauses);
    }
}
