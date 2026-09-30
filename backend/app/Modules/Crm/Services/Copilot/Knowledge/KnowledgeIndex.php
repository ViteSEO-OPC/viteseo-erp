<?php

namespace App\Modules\Crm\Services\Copilot\Knowledge;

use RuntimeException;

/**
 * The Copilot's curated knowledge — the same user-facing articles the rule-based Copilot already
 * ships to every browser (`knowledge.json`, `computations.json`), copied byte-for-byte into
 * `resources/copilot/knowledge` and kept identical by a parity gate.
 *
 * NOTHING ELSE IS KNOWLEDGE. No engineering docs, runbooks, configuration, CLAUDE.md or audit
 * material is ever loaded here. Computation docs drop `route` and `verifiedAgainst`, which name code
 * paths rather than explain a figure.
 *
 * Search is a small keyword ranker used ONLY to choose which articles to supply; it never produces an
 * answer. The model reads the supplied articles and writes its own reply.
 */
class KnowledgeIndex
{
    /** Longest article text supplied to the model, in characters. */
    public const MAX_ARTICLE_CHARS = 1500;

    /** Words too common to rank on, English and Tagalog. */
    private const STOPWORDS = [
        'a', 'an', 'the', 'is', 'are', 'am', 'was', 'be', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'my',
        'me', 'i', 'you', 'your', 'it', 'this', 'that', 'what', 'which', 'how', 'why', 'do', 'does', 'can',
        'with', 'about', 'tell', 'should', 'there', 'any', 'have', 'has', 'ang', 'ng', 'sa', 'na', 'ko', 'mo',
        'ba', 'ano', 'mga', 'ako', 'ka', 'si', 'yung', 'po', 'kung', 'lang', 'naman', 'nga', 'dapat',
    ];

    /** @var list<array{id: string, title: string, text: string, tokens: array<string, float>}>|null */
    private ?array $articles = null;

    /**
     * The best-matching articles for a question, highest first. Empty when nothing matches.
     *
     * @return list<array{id: string, title: string, text: string}>
     */
    public function search(string $question, int $limit = 3): array
    {
        $query = array_unique($this->tokenize($question));
        if ($query === []) {
            return [];
        }

        $articles = $this->articles();
        $documentFrequency = [];
        foreach ($articles as $article) {
            foreach (array_keys($article['tokens']) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $total = count($articles);
        $scored = [];
        foreach ($articles as $article) {
            $score = 0.0;
            foreach ($query as $token) {
                if (isset($article['tokens'][$token])) {
                    $score += $article['tokens'][$token] * log(1 + $total / $documentFrequency[$token]);
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'article' => $article];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['article']['id'], $b['article']['id']));

        return array_map(
            static fn (array $hit): array => ['id' => $hit['article']['id'], 'title' => $hit['article']['title'], 'text' => $hit['article']['text']],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * Specific articles by id, in the order asked. Unknown ids are skipped.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, title: string, text: string}>
     */
    public function get(array $ids): array
    {
        $byId = [];
        foreach ($this->articles() as $article) {
            $byId[$article['id']] = ['id' => $article['id'], 'title' => $article['title'], 'text' => $article['text']];
        }

        return array_values(array_filter(array_map(static fn (string $id): ?array => $byId[$id] ?? null, $ids)));
    }

    /** @return list<array{id: string, title: string, text: string, tokens: array<string, float>}> */
    private function articles(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }

        $articles = [];
        foreach ($this->load('knowledge.json')['knowledge'] ?? [] as $row) {
            $articles[] = $this->article(
                (string) $row['id'],
                (string) $row['question'],
                (string) $row['answer'],
                (string) ($row['keywords'] ?? ''),
            );
        }
        foreach ($this->load('computations.json')['computations'] ?? [] as $row) {
            $parts = [
                (string) ($row['measures'] ?? ''),
                'Formula: '.($row['formula'] ?? ''),
                'Numerator: '.($row['numerator'] ?? ''),
                'Denominator: '.($row['denominator'] ?? ''),
                'Undefined when: '.($row['undefinedWhen'] ?? ''),
                (string) ($row['reasoning'] ?? ''),
                'Caveats: '.implode(' ', array_map('strval', (array) ($row['caveats'] ?? []))),
                'Shown on: '.($row['surface'] ?? ''),
            ];
            $articles[] = $this->article(
                'computation:'.$row['id'],
                'How '.$row['label'].' is computed',
                implode("\n", $parts),
                (string) ($row['keywords'] ?? ''),
            );
        }

        return $this->articles = $articles;
    }

    /** @return array{id: string, title: string, text: string, tokens: array<string, float>} */
    private function article(string $id, string $title, string $body, string $keywords): array
    {
        $text = mb_substr(str_replace('**', '', trim($body)), 0, self::MAX_ARTICLE_CHARS);

        // Title and keywords carry the article's intent, so they rank above body words.
        $tokens = [];
        foreach ([[$title, 3.0], [$keywords, 2.0], [$text, 1.0]] as [$field, $weight]) {
            foreach ($this->tokenize($field) as $token) {
                $tokens[$token] = ($tokens[$token] ?? 0) + $weight;
            }
        }

        return ['id' => $id, 'title' => $title, 'text' => $text, 'tokens' => $tokens];
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 1 && ! in_array($w, self::STOPWORDS, true)));
    }

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        $path = resource_path('copilot/knowledge/'.$file);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Copilot knowledge file is missing or invalid: {$file}");
        }

        return $data;
    }
}
