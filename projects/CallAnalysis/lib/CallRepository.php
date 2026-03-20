<?php

declare(strict_types=1);

final class CallRepository
{
    public function __construct(private PDO $pdo) {}

    public function createCall(string $originalFilename, string $storedPath, ?string $mime, ?int $sizeBytes): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ca_calls (original_filename, stored_path, mime, size_bytes, status) VALUES (?,?,?,?,?)'
        );
        $st->execute([$originalFilename, $storedPath, $mime, $sizeBytes, 'pending']);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $callId, string $status, ?string $error = null): void
    {
        $st = $this->pdo->prepare('UPDATE ca_calls SET status = ?, error_message = ? WHERE id = ?');
        $st->execute([$status, $error, $callId]);
    }

    public function setDuration(int $callId, float $seconds): void
    {
        $st = $this->pdo->prepare('UPDATE ca_calls SET duration_seconds = ? WHERE id = ?');
        $st->execute([$seconds, $callId]);
    }

    public function getCall(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ca_calls WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listCalls(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $st = $this->pdo->prepare(
            'SELECT c.*, a.sentiment, a.overall_score, a.summary
             FROM ca_calls c
             LEFT JOIN ca_analyses a ON a.call_id = c.id
             ORDER BY c.created_at DESC
             LIMIT ' . $limit
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteSegments(int $callId): void
    {
        $this->pdo->prepare('DELETE FROM ca_transcript_segments WHERE call_id = ?')->execute([$callId]);
    }

    public function insertSegment(int $callId, int $index, float $start, float $end, string $text): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ca_transcript_segments (call_id, segment_index, start_sec, end_sec, text) VALUES (?,?,?,?,?)'
        );
        $st->execute([$callId, $index, $start, $end, $text]);
    }

    public function getSegments(int $callId): array
    {
        $st = $this->pdo->prepare(
            'SELECT segment_index, start_sec, end_sec, text FROM ca_transcript_segments WHERE call_id = ? ORDER BY segment_index ASC'
        );
        $st->execute([$callId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Persist extended fields with positional binds only. Stored as LONGTEXT JSON text (see schema.sql).
     *
     * @param array{questionnaire: string, top_discussed: string, positive_observations: string, negative_observations: string} $extended
     */
    private function flushExtendedAnalysisJsonColumns(int $callId, array $extended): void
    {
        $st = $this->pdo->prepare(
            'UPDATE ca_analyses SET questionnaire_coverage_json = ?, top_discussed_json = ?, positive_observations_json = ?, negative_observations_json = ? WHERE call_id = ? LIMIT 1'
        );
        $st->execute([
            $extended['questionnaire'],
            $extended['top_discussed'],
            $extended['positive_observations'],
            $extended['negative_observations'],
            $callId,
        ]);
    }

    public function saveAnalysis(int $callId, array $data): void
    {
        $this->pdo->prepare('DELETE FROM ca_agent_dimension_scores WHERE call_id = ?')->execute([$callId]);
        $this->pdo->prepare('DELETE FROM ca_action_items WHERE call_id = ?')->execute([$callId]);

        $st = $this->pdo->prepare(
            'INSERT INTO ca_analyses (
                call_id, summary, purpose, main_topics, outcome, sentiment, sentiment_rationale,
                overall_score, agent_talk_pct, customer_talk_pct,
                quality_pacing, quality_structure, quality_engagement, quality_notes,
                keywords_json, patterns_json, conversation_shifts_json,
                analysis_model, processed_at
            ) VALUES (
                :call_id, :summary, :purpose, :main_topics, :outcome, :sentiment, :sentiment_rationale,
                :overall_score, :agent_talk_pct, :customer_talk_pct,
                :quality_pacing, :quality_structure, :quality_engagement, :quality_notes,
                :keywords_json, :patterns_json, :conversation_shifts_json,
                :analysis_model, NOW()
            )
            ON DUPLICATE KEY UPDATE
                summary = VALUES(summary),
                purpose = VALUES(purpose),
                main_topics = VALUES(main_topics),
                outcome = VALUES(outcome),
                sentiment = VALUES(sentiment),
                sentiment_rationale = VALUES(sentiment_rationale),
                overall_score = VALUES(overall_score),
                agent_talk_pct = VALUES(agent_talk_pct),
                customer_talk_pct = VALUES(customer_talk_pct),
                quality_pacing = VALUES(quality_pacing),
                quality_structure = VALUES(quality_structure),
                quality_engagement = VALUES(quality_engagement),
                quality_notes = VALUES(quality_notes),
                keywords_json = VALUES(keywords_json),
                patterns_json = VALUES(patterns_json),
                conversation_shifts_json = VALUES(conversation_shifts_json),
                analysis_model = VALUES(analysis_model),
                processed_at = NOW()'
        );
        $sent = strtolower(trim((string) ($data['sentiment'] ?? 'neutral')));
        if (!in_array($sent, ['positive', 'neutral', 'negative'], true)) {
            $sent = 'neutral';
        }

        $extended = self::buildExtendedAnalysisJsonStrings($data);

        $st->execute([
            'call_id' => $callId,
            'summary' => $data['summary'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'main_topics' => $data['main_topics'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'sentiment' => $sent,
            'sentiment_rationale' => $data['sentiment_rationale'] ?? null,
            'overall_score' => self::numOrNull($data['overall_score'] ?? null),
            'agent_talk_pct' => self::numOrNull($data['agent_talk_pct'] ?? null),
            'customer_talk_pct' => self::numOrNull($data['customer_talk_pct'] ?? null),
            'quality_pacing' => self::numOrNull($data['quality_pacing'] ?? null),
            'quality_structure' => self::numOrNull($data['quality_structure'] ?? null),
            'quality_engagement' => self::numOrNull($data['quality_engagement'] ?? null),
            'quality_notes' => $data['quality_notes'] ?? null,
            'keywords_json' => isset($data['keywords']) ? json_encode($data['keywords'], JSON_UNESCAPED_UNICODE) : null,
            'patterns_json' => isset($data['behavioral_signals']) ? json_encode($data['behavioral_signals'], JSON_UNESCAPED_UNICODE) : null,
            'conversation_shifts_json' => isset($data['conversation_shifts']) ? json_encode($data['conversation_shifts'], JSON_UNESCAPED_UNICODE) : null,
            'analysis_model' => $data['analysis_model'] ?? null,
        ]);

        $this->flushExtendedAnalysisJsonColumns($callId, $extended);

        $dims = $data['agent_dimensions'] ?? [];
        foreach ($dims as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $score = (int) round((float) ($row['score'] ?? 0));
            if ($score < 1) {
                $score = 1;
            }
            if ($score > 10) {
                $score = 10;
            }
            $ins = $this->pdo->prepare(
                'INSERT INTO ca_agent_dimension_scores (call_id, dimension_key, score, justification) VALUES (?,?,?,?)'
            );
            $ins->execute([$callId, (string) $key, $score, $row['justification'] ?? null]);
        }

        $items = $data['action_items'] ?? [];
        if (is_array($items)) {
            $ord = 0;
            foreach ($items as $t) {
                if (!is_string($t) || trim($t) === '') {
                    continue;
                }
                $ins = $this->pdo->prepare(
                    'INSERT INTO ca_action_items (call_id, item_text, sort_order) VALUES (?,?,?)'
                );
                $ins->execute([$callId, trim($t), $ord++]);
            }
        }
    }

    public function getAnalysis(int $callId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ca_analyses WHERE call_id = ?');
        $st->execute([$callId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAgentScores(int $callId): array
    {
        $st = $this->pdo->prepare(
            'SELECT dimension_key, score, justification FROM ca_agent_dimension_scores WHERE call_id = ? ORDER BY dimension_key'
        );
        $st->execute([$callId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActionItems(int $callId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, item_text FROM ca_action_items WHERE call_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$callId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Dashboard aggregates for calls with status = ready */
    public function dashboardStats(): array
    {
        $sql = <<<'SQL'
SELECT
  COUNT(*) AS total_calls,
  AVG(c.duration_seconds) AS avg_duration,
  AVG(a.overall_score) AS avg_score,
  AVG(
    CASE a.sentiment
      WHEN 'positive' THEN 1.0
      WHEN 'neutral' THEN 0.5
      WHEN 'negative' THEN 0.0
      ELSE NULL
    END
  ) * 10 AS avg_sentiment_index,
  (SELECT COUNT(*) FROM ca_action_items ai INNER JOIN ca_calls cc ON cc.id = ai.call_id WHERE cc.status = 'ready') AS action_items_total
FROM ca_calls c
INNER JOIN ca_analyses a ON a.call_id = c.id
WHERE c.status = 'ready'
SQL;
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'total_calls' => 0,
                'avg_duration' => null,
                'avg_score' => null,
                'avg_sentiment_index' => null,
                'action_items_total' => 0,
            ];
        }
        return $row;
    }

    /**
     * Aggregate keywords from ready calls' keywords_json; count = times the term appears (per call, each list entry counts once).
     *
     * @return list<array{keyword: string, count: int}>
     */
    public function aggregateTopKeywordCounts(int $limit = 10): array
    {
        $st = $this->pdo->query(
            "SELECT a.keywords_json FROM ca_analyses a
             INNER JOIN ca_calls c ON c.id = a.call_id AND c.status = 'ready'
             WHERE a.keywords_json IS NOT NULL"
        );
        $counts = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $j = json_decode((string) $row['keywords_json'], true);
            if (!is_array($j)) {
                continue;
            }
            foreach ($j as $kw) {
                if (!is_string($kw)) {
                    continue;
                }
                $k = mb_strtolower(trim($kw));
                if ($k === '') {
                    continue;
                }
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        arsort($counts, SORT_NUMERIC);
        $out = [];
        foreach ($counts as $kw => $n) {
            $out[] = ['keyword' => $kw, 'count' => (int) $n];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array{id: int, status: string, overall_score: ?float, sentiment: ?string}>
     */
    public function getCallsStatusForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, 50);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT c.id, c.status, a.overall_score, a.sentiment
                FROM ca_calls c
                LEFT JOIN ca_analyses a ON a.call_id = c.id
                WHERE c.id IN ($placeholders)";
        $st = $this->pdo->prepare($sql);
        $st->execute($ids);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'overall_score' => $row['overall_score'] !== null && $row['overall_score'] !== '' ? (float) $row['overall_score'] : null,
                'sentiment' => isset($row['sentiment']) && $row['sentiment'] !== null && $row['sentiment'] !== ''
                    ? (string) $row['sentiment']
                    : null,
            ];
        }
        return $out;
    }

    private static function numOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    private static function jsonOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v)) {
            return null;
        }
        if ($v === []) {
            return null;
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** @var list<string> Same order as OpenAIClient::QUESTIONNAIRE_TOPICS / call.php defaults */
    private const QUESTIONNAIRE_TOPICS = [
        'Budget Discussion',
        'Competitor Comparison',
        'Kitchen Size / Scope',
        'Cabinet Style Preference',
        'Remodeling Full Kitchen?',
    ];

    /**
     * Always returns four non-null JSON strings so MariaDB JSON columns are never left NULL
     * (empty lists use "[]"; questionnaire always has 5 rows).
     *
     * @return array{questionnaire: string, top_discussed: string, positive_observations: string, negative_observations: string}
     */
    private static function buildExtendedAnalysisJsonStrings(array $data): array
    {
        $q = $data['questionnaire_coverage'] ?? null;
        if (!is_array($q) || $q === []) {
            $q = self::defaultQuestionnaireRows();
        }

        $top = self::normalizeTopDiscussed($data['top_discussed'] ?? null);
        if ($top === []) {
            $top = self::deriveTopDiscussedFromKeywords($data['keywords'] ?? null);
        }

        $pos = $data['positive_observations'] ?? null;
        if (!is_array($pos)) {
            $pos = [];
        }
        $pos = array_values(array_filter($pos, static fn (mixed $x): bool => is_string($x) && trim($x) !== ''));

        $neg = $data['negative_observations'] ?? null;
        if (!is_array($neg)) {
            $neg = [];
        }
        $neg = array_values(array_filter($neg, static fn (mixed $x): bool => is_string($x) && trim($x) !== ''));

        return [
            'questionnaire' => self::jsonEncodeAlways($q, self::defaultQuestionnaireRows()),
            'top_discussed' => self::jsonEncodeAlways($top, []),
            'positive_observations' => self::jsonEncodeAlways($pos, []),
            'negative_observations' => self::jsonEncodeAlways($neg, []),
        ];
    }

    /**
     * @return list<array{topic: string, asked: bool}>
     */
    private static function defaultQuestionnaireRows(): array
    {
        $out = [];
        foreach (self::QUESTIONNAIRE_TOPICS as $topic) {
            $out[] = ['topic' => $topic, 'asked' => false];
        }

        return $out;
    }

    /** @return list<array{emoji: string, label: string}> */
    private static function deriveTopDiscussedFromKeywords(mixed $keywords): array
    {
        if (!is_array($keywords)) {
            return [];
        }
        $out = [];
        foreach (array_slice($keywords, 0, 8) as $k) {
            if (!is_string($k)) {
                continue;
            }
            $label = trim($k);
            if ($label === '') {
                continue;
            }
            $out[] = ['emoji' => '💬', 'label' => $label];
        }

        return $out;
    }

    /**
     * @param array<mixed> $v
     * @param array<mixed> $fallback
     */
    private static function jsonEncodeAlways(array $v, array $fallback): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($v, $flags);
        if ($json !== false) {
            return $json;
        }
        $json = json_encode($fallback, $flags);

        return $json !== false ? $json : '[]';
    }

    /**
     * @return list<array{emoji: string, label: string}>
     */
    private static function normalizeTopDiscussed(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $emoji = trim((string) ($row['emoji'] ?? ''));
            if ($emoji === '') {
                $emoji = '💬';
            }
            $out[] = ['emoji' => mb_substr($emoji, 0, 8), 'label' => $label];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }
}
