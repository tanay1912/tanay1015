<?php

declare(strict_types=1);

final class OpenAIClient
{
    private const TRANSCRIBE_URL = 'https://api.openai.com/v1/audio/transcriptions';
    private const CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    /** @var list<string> Must stay aligned with ca_questionnaire_default_topics() in call.php */
    private const QUESTIONNAIRE_TOPICS = [
        'Budget Discussion',
        'Competitor Comparison',
        'Kitchen Size / Scope',
        'Cabinet Style Preference',
        'Remodeling Full Kitchen?',
    ];

    public function __construct(private string $apiKey) {}

    /**
     * @return array{duration?: float, segments: list<array{start: float, end: float, text: string}>}
     */
    public function transcribeVerbose(string $filePath, string $mime = 'audio/mpeg', string $uploadFilename = 'recording.mp3'): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not set');
        }

        $boundary = '----CAForm' . bin2hex(random_bytes(8));
        $body = '';
        $fields = [
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'segment',
        ];
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploadFilename) ?: 'recording.bin';
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $safeName . '"' . "\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= file_get_contents($filePath);
        $body .= "\r\n--{$boundary}--\r\n";

        $ch = curl_init(self::TRANSCRIBE_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = $raw === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Transcription request failed: ' . $curlErr);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Transcription API error ' . $code . ': ' . substr($raw, 0, 2000));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid transcription JSON');
        }

        $segments = [];
        foreach ($json['segments'] ?? [] as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start' => (float) ($seg['start'] ?? 0),
                'end' => (float) ($seg['end'] ?? 0),
                'text' => $text,
            ];
        }

        if ($segments === [] && isset($json['text'])) {
            $segments[] = [
                'start' => 0.0,
                'end' => (float) ($json['duration'] ?? 0),
                'text' => trim((string) $json['text']),
            ];
        }

        $duration = isset($json['duration']) ? (float) $json['duration'] : null;
        if ($duration === null && $segments !== []) {
            $last = $segments[array_key_last($segments)];
            $duration = (float) $last['end'];
        }

        return [
            'duration' => $duration ?? 0.0,
            'segments' => $segments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeTranscript(string $transcriptText, string $model = 'gpt-4o-mini'): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not set');
        }

        $schemaHint = <<<'PROMPT'
You are an expert sales call QA analyst. Given the full call transcript, return a single JSON object with these exact keys (no markdown):
{
  "summary": "2-4 sentence overview",
  "purpose": "why the call happened",
  "main_topics": "comma-separated or short paragraph",
  "outcome": "what was decided or next step",
  "sentiment": "positive" | "neutral" | "negative",
  "sentiment_rationale": "one short sentence",
  "overall_score": number 0-10 (quality: sentiment + agent professionalism + communication),
  "agent_talk_pct": number 0-100 (estimate from transcript who is agent vs customer; agent = company rep),
  "customer_talk_pct": number 0-100 (should sum to ~100 with agent_talk_pct),
  "quality_pacing": number 0-10,
  "quality_structure": number 0-10,
  "quality_engagement": number 0-10,
  "quality_notes": "brief notes on pacing, structure, engagement",
  "keywords": ["up to 10 short topic keywords from the call"],
  "questionnaire_coverage": [
    { "topic": "Budget Discussion", "asked": true or false },
    { "topic": "Competitor Comparison", "asked": true or false },
    { "topic": "Kitchen Size / Scope", "asked": true or false },
    { "topic": "Cabinet Style Preference", "asked": true or false },
    { "topic": "Remodeling Full Kitchen?", "asked": true or false }
  ],
  "top_discussed": [
    { "emoji": "💰", "label": "Budget" }
  ],
  "positive_observations": ["REQUIRED: 2-5 strings, coaching-style strengths grounded ONLY in the transcript"],
  "negative_observations": ["REQUIRED: 2-5 strings, coaching gaps grounded ONLY in the transcript"],
  "behavioral_signals": ["notable emotional or behavioral cues"],
  "conversation_shifts": ["moments tone or topic shifted notably"],
  "agent_dimensions": {
    "communication_clarity": { "score": 1-10, "justification": "short" },
    "politeness": { "score": 1-10, "justification": "short" },
    "business_knowledge": { "score": 1-10, "justification": "short" },
    "problem_handling": { "score": 1-10, "justification": "short" },
    "listening_ability": { "score": 1-10, "justification": "short" }
  },
  "action_items": ["specific follow-ups or commitments mentioned"]
}
Include every questionnaire_coverage row exactly as listed (same topic strings).

questionnaire_coverage rules — set asked=true if that THEME is substantively present anywhere in the transcript (either speaker). The customer volunteering information counts; the agent does not need to ask a scripted question.
- "Budget Discussion": budget, price, cost, spend, investment, dollar amounts, affordability, financing, "how much", quote ranges, allowances.
- "Competitor Comparison": other companies, vendors, shopping around, comparing quotes, big-box stores (e.g. Home Depot, Lowe's), "someone else quoted".
- "Kitchen Size / Scope": kitchen size, dimensions, square footage, layout (L-shape, U-shape, galley), island plans, keeping/changing layout, how large the space is, scope of the project area.
- "Cabinet Style Preference": cabinet door style (shaker, flat panel, raised panel, traditional), colors, finishes, wood vs painted, material preferences.
- "Remodeling Full Kitchen?": remodel, renovation, replacing, refresh, rental.

Set asked=false only when that theme is truly absent or a single vague passing word with no substance.

top_discussed: up to 8 objects, each with emoji and label for the strongest themes discussed.

CRITICAL: positive_observations and negative_observations MUST each be a non-empty JSON array of at least 2 distinct strings (max 5 each). They must be written ONLY from evidence in the transcript—do not copy summary, quality_notes, or behavioral_signals verbatim; write fresh coaching bullets. Do not leave them empty or null.
Use only valid JSON. If uncertain, still output best-effort numbers and strings.
PROMPT;

        $content = $this->chatCompletionContent(
            $model,
            [
                ['role' => 'system', 'content' => $schemaHint],
                ['role' => 'user', 'content' => "Transcript:\n\n" . $transcriptText],
            ],
            0.2
        );
        $parsed = self::decodeAssistantJson($content);
        if ($parsed === []) {
            $probe = trim(preg_replace('/^```(?:json)?\s*\R?|\R?```$/s', '', trim($content)));
            if (strlen($probe) > 20) {
                throw new RuntimeException('Model did not return parseable JSON: ' . substr($content, 0, 600));
            }
            throw new RuntimeException('Model returned empty JSON.');
        }

        /*
         * Dedicated JSON pass: the main analysis object is large; models often drop or mis-shape
         * questionnaire_coverage and observations. Always fill these from a second, focused request.
         */
        $packPrompt = <<<'PACK'
You analyze a kitchen cabinet sales / consultation call transcript. Reply with ONE JSON object and only these three keys (no markdown, no extra keys):

1) questionnaire_coverage — JSON array of exactly 5 objects in this order, with these exact "topic" strings:
   {"topic":"Budget Discussion","asked":true or false},
   {"topic":"Competitor Comparison","asked":true or false},
   {"topic":"Kitchen Size / Scope","asked":true or false},
   {"topic":"Cabinet Style Preference","asked":true or false},
   {"topic":"Remodeling Full Kitchen?","asked":true or false}

   For each row, asked=true if that THEME is substantively discussed anywhere in the call (either agent or customer). Customer volunteering counts; a formal "discovery question" from the agent is NOT required.

   Rubric:
   - Budget Discussion → true if: budget, price, cost, spend, investment, dollar amounts, affordability, financing, "how much", quote or price ranges, allowances.
   - Competitor Comparison → true if: other companies, vendors, shopping around, comparing quotes, big-box or other retailers, bids from elsewhere.
   - Kitchen Size / Scope → true if: kitchen dimensions/size, square feet, layout type (L-shape, U-shape, galley), island, changing layout, room size, scope of the kitchen space.
   - Cabinet Style Preference → true if: cabinet door style (shaker, flat panel, raised panel, traditional), colors, finishes, painted vs wood, material or look preferences.
   - Remodeling Full Kitchen? → true if: full kitchen remodel vs cabinets-only, renovating the whole kitchen, extent of remodel, new kitchen project vs refresh, primary home vs rental when it clarifies project scope.

   asked=false only if that theme is truly absent (do not force true).

2) positive_observations — JSON array of 2 to 5 short strings: agent strengths grounded only in the transcript.

3) negative_observations — JSON array of 2 to 5 short strings: coaching gaps grounded only in the transcript.

Use real JSON booleans for asked. Each observation must be a plain string, not an object.
PACK;

        $packRaw = $this->chatCompletionContent(
            $model,
            [
                ['role' => 'system', 'content' => $packPrompt],
                ['role' => 'user', 'content' => "Transcript:\n\n" . $transcriptText],
            ],
            0.15
        );
        $pack = self::unwrapAssistantJsonObject(self::decodeAssistantJson($packRaw));
        if (self::discoveryPackNeedsRetry($pack)) {
            $packRawRetry = $this->chatCompletionContent(
                $model,
                [
                    [
                        'role' => 'system',
                        'content' => $packPrompt . "\n\nOutput raw JSON only. No markdown fences. No commentary before or after the object.",
                    ],
                    ['role' => 'user', 'content' => "Transcript:\n\n" . $transcriptText],
                ],
                0.25
            );
            $packRetry = self::unwrapAssistantJsonObject(self::decodeAssistantJson($packRawRetry));
            if (!self::discoveryPackNeedsRetry($packRetry)) {
                $pack = $packRetry;
            }
        }

        $qcRaw = $pack['questionnaire_coverage']
            ?? $pack['questionnaireCoverage']
            ?? $pack['discovery_coverage']
            ?? null;
        $parsed['questionnaire_coverage'] = self::mergeQuestionnaireWithTranscriptSignals(
            $transcriptText,
            self::finalizeQuestionnaireRows($qcRaw)
        );

        $pos = self::normalizeObservationList(self::extractPositiveObservationsFromDecoded($pack));
        $neg = self::normalizeObservationList(self::extractNegativeObservationsFromDecoded($pack));
        for ($obsAttempt = 0; $obsAttempt < 4 && (count($pos) < 2 || count($neg) < 2); $obsAttempt++) {
            $repair = self::unwrapAssistantJsonObject($this->fetchAiObservationsOnly($transcriptText, $model, $obsAttempt > 1 ? 0.35 : 0.2));
            $rp = self::normalizeObservationList(self::extractPositiveObservationsFromDecoded($repair));
            $rn = self::normalizeObservationList(self::extractNegativeObservationsFromDecoded($repair));
            if (count($pos) < 2) {
                $pos = count($rp) >= 2 ? $rp : array_values(array_unique([...$rp, ...$pos]));
            }
            if (count($neg) < 2) {
                $neg = count($rn) >= 2 ? $rn : array_values(array_unique([...$rn, ...$neg]));
            }
        }
        if (count($pos) < 2 || count($neg) < 2) {
            $lastChance = self::unwrapAssistantJsonObject($this->fetchAiObservationsLastResort($transcriptText, $model));
            $lp = self::normalizeObservationList(self::extractPositiveObservationsFromDecoded($lastChance));
            $ln = self::normalizeObservationList(self::extractNegativeObservationsFromDecoded($lastChance));
            if (count($pos) < 2 && count($lp) >= 2) {
                $pos = $lp;
            }
            if (count($neg) < 2 && count($ln) >= 2) {
                $neg = $ln;
            }
        }
        $parsed['positive_observations'] = array_slice($pos, 0, 5);
        $parsed['negative_observations'] = array_slice($neg, 0, 5);

        $parsed['analysis_model'] = $model;
        return $parsed;
    }

    /**
     * @return list<array{topic: string, asked: bool}>
     */
    private static function finalizeQuestionnaireRows(mixed $raw): array
    {
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $topic = trim((string) ($row['topic'] ?? ''));
                if ($topic === '') {
                    continue;
                }
                $map[mb_strtolower($topic)] = self::coerceAskedBool($row['asked'] ?? false);
            }
        }
        $out = [];
        foreach (self::QUESTIONNAIRE_TOPICS as $topic) {
            $out[] = [
                'topic' => $topic,
                'asked' => $map[mb_strtolower($topic)] ?? false,
            ];
        }

        return $out;
    }

    private static function coerceAskedBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int) $v) !== 0;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === '' || in_array($s, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
            if (in_array($s, ['1', 'true', 'yes', 'y', 'asked', 'covered'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * OR the model's booleans with keyword signals so "all false" is rare when the transcript clearly mentions themes.
     *
     * @param list<array{topic: string, asked: bool}> $rows
     * @return list<array{topic: string, asked: bool}>
     */
    private static function mergeQuestionnaireWithTranscriptSignals(string $transcript, array $rows): array
    {
        $t = mb_strtolower($transcript);
        $out = [];
        foreach ($rows as $row) {
            $topic = $row['topic'];
            $fromAi = $row['asked'];
            $fromText = self::transcriptHasTopicSignals($topic, $t);
            $out[] = [
                'topic' => $topic,
                'asked' => $fromAi || $fromText,
            ];
        }

        return $out;
    }

    private static function transcriptHasTopicSignals(string $topic, string $transcriptLower): bool
    {
        foreach (self::topicSignalNeedles($topic) as $needle) {
            if ($needle === '$') {
                if (str_contains($transcriptLower, '$')) {
                    return true;
                }
                if (preg_match('/\b\d{1,3}(?:,\d{3})+\b|\b\d+k\b|\d+\s*(?:thousand|hundred)\b|\b\d{4,5}\s*(?:dollars|bucks)?\b/u', $transcriptLower)) {
                    return true;
                }
                continue;
            }
            if (mb_stripos($transcriptLower, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Substrings / tokens to search for (lowercase needles; transcript already lowercased).
     *
     * @return list<string>
     */
    private static function topicSignalNeedles(string $topic): array
    {
        return match ($topic) {
            'Budget Discussion' => [
                'budget', 'price', 'cost', 'afford', 'spend', 'financ', 'quote', 'how much',
                'investment', 'allowance', 'thousand', 'dollar', '$', 'payment', 'range',
            ],
            'Competitor Comparison' => [
                'competitor', 'competition', 'home depot', 'lowe', ' lowes', 'menards', 'ikea',
                'another quote', 'other vendor', 'other company', 'shopping around', 'compare quotes',
                'elsewhere', 'big box', 'another store',
            ],
            'Kitchen Size / Scope' => [
                'measurement', 'layout', 'l-shape', 'l shape', 'u-shape', 'galley', 'island',
                'square foot', 'square feet', 'feet by', ' by ', 'dimension', 'open shelf',
                'shelving', 'entire wall', 'upper cabinet', 'wall of', 'kitchen size', 'space',
            ],
            'Cabinet Style Preference' => [
                'shaker', 'flat panel', 'raised panel', 'modern', 'traditional', 'transitional',
                'cabinet style', 'door style', 'glass door', 'hardware', 'handle', 'knob',
                'handle-less', 'matte black', 'brushed nickel', 'white cabinet', 'color',
                'finish', 'wood tone', 'minimal', 'decor',
            ],
            'Remodeling Full Kitchen?' => [
                'remodel', 'renovat', 'full kitchen', 'whole kitchen', 'kitchen project',
                'design consultation', 'design option', 'replace the', 'new kitchen',
                'primary home', 'rental', 'tear out', 'gut', 'cabinet design',
            ],
            default => [],
        };
    }

    /**
     * Second pass: only AI-generated coaching bullets (transcript-grounded).
     *
     * @return array<string, mixed>
     */
    private function fetchAiObservationsOnly(string $transcriptText, string $model, float $temperature = 0.2): array
    {
        $system = <<<'SYS'
Return a single JSON object with exactly two keys: positive_observations and negative_observations.

Each value must be an array of 2 to 5 short plain strings (not objects). Base every string on the transcript.

positive_observations: strengths of the company rep (empathy, clarity, structure, product knowledge, next steps, listening, de-escalation, offering options).
negative_observations: coaching gaps (missed discovery, weak follow-up, talking over the customer, vague commitments, not confirming understanding).

If the rep is mostly doing well, still find at least 2 mild improvement ideas for negative_observations. If the call is short, use specific moments from the text.

No other keys. No markdown. No empty arrays.
SYS;
        $raw = $this->chatCompletionContent(
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Transcript:\n\n" . $transcriptText],
            ],
            $temperature
        );
        return self::decodeAssistantJson($raw);
    }

    /**
     * Final attempt with stricter JSON-only instruction.
     *
     * @return array<string, mixed>
     */
    private function fetchAiObservationsLastResort(string $transcriptText, string $model): array
    {
        $system = <<<'SYS'
Output only valid JSON (no markdown). Keys: positive_observations, negative_observations.
Each must be an array of exactly 3 strings. Content must come from the transcript only.
positive_observations = 3 brief strengths of the agent.
negative_observations = 3 brief improvement suggestions (use gentle coaching tone).
SYS;
        $raw = $this->chatCompletionContent(
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $transcriptText],
            ],
            0.4
        );

        return self::decodeAssistantJson($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeAssistantJson(string $content): array
    {
        $t = trim($content);
        if ($t === '') {
            return [];
        }
        if (preg_match('/^```(?:json)?\s*\R?(.*?)\R?```\s*$/s', $t, $m)) {
            $t = trim($m[1]);
        }
        $parsed = json_decode($t, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($parsed)) {
            return $parsed;
        }
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($t, $start, $end - $start + 1);
            $parsed = json_decode($slice, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * Models sometimes wrap the payload in { "result": { ... } } even with json_object mode.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private static function unwrapAssistantJsonObject(array $parsed): array
    {
        if (count($parsed) === 1) {
            $k = array_key_first($parsed);
            if (is_string($k) && in_array($k, ['result', 'data', 'response', 'output', 'answer'], true)) {
                $inner = $parsed[$k];
                if (is_array($inner)) {
                    return $inner;
                }
            }
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractPositiveObservationsFromDecoded(array $decoded): mixed
    {
        foreach ([
            'positive_observations',
            'positiveObservations',
            'positives',
            'strengths',
            'positive_coaching',
            'positive',
        ] as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }
        if (isset($decoded['observations']) && is_array($decoded['observations'])) {
            $o = $decoded['observations'];

            return $o['positive'] ?? $o['positives'] ?? $o['strengths'] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractNegativeObservationsFromDecoded(array $decoded): mixed
    {
        foreach ([
            'negative_observations',
            'negativeObservations',
            'negatives',
            'gaps',
            'improvements',
            'coaching_gaps',
            'negative',
        ] as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }
        if (isset($decoded['observations']) && is_array($decoded['observations'])) {
            $o = $decoded['observations'];

            return $o['negative'] ?? $o['negatives'] ?? $o['gaps'] ?? null;
        }

        return null;
    }

    /** @param array<string, mixed> $pack */
    private static function discoveryPackNeedsRetry(array $pack): bool
    {
        if ($pack === []) {
            return true;
        }
        $qc = $pack['questionnaire_coverage']
            ?? $pack['questionnaireCoverage']
            ?? $pack['discovery_coverage']
            ?? null;

        return !is_array($qc) || $qc === [];
    }

    /**
     * @return list<string>
     */
    private static function normalizeObservationList(mixed $raw): array
    {
        $list = self::coerceObservationsToList($raw);
        $out = [];
        foreach ($list as $item) {
            $t = self::observationItemToString($item);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * Models often return a single prose string or JSON-in-a-string instead of a JSON array.
     *
     * @return list<mixed>
     */
    private static function coerceObservationsToList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                return [];
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return self::coerceObservationsToList($decoded);
            }

            return self::splitObservationStringIntoBullets($trim);
        }
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw['items']) && is_array($raw['items'])) {
            return self::coerceObservationsToList($raw['items']);
        }
        if (isset($raw['list']) && is_array($raw['list'])) {
            return self::coerceObservationsToList($raw['list']);
        }
        if (isset($raw['bullets']) && is_array($raw['bullets'])) {
            return self::coerceObservationsToList($raw['bullets']);
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private static function splitObservationStringIntoBullets(string $s): array
    {
        $lines = preg_split('/\s*\R\s*/', $s) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^[\s•\-\*]+/u', '', trim($line));
            $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            if ($line !== '' && mb_strlen($line) > 1) {
                $out[] = $line;
            }
        }
        if (count($out) >= 2) {
            return array_slice($out, 0, 8);
        }
        if (preg_match_all('/[^.!?]+[.!?]+/u', $s, $m)) {
            foreach ($m[0] as $sent) {
                $t = trim($sent);
                if (mb_strlen($t) > 20) {
                    $out[] = $t;
                }
                if (count($out) >= 8) {
                    break;
                }
            }
        }
        if (count($out) >= 2) {
            return array_slice($out, 0, 8);
        }
        $one = trim($s);
        if ($one !== '' && mb_strlen($one) > 10) {
            return [$one];
        }

        return [];
    }

    private static function observationItemToString(mixed $item): string
    {
        if (is_string($item)) {
            return trim($item);
        }
        if (!is_array($item)) {
            return '';
        }
        foreach (['text', 'observation', 'point', 'note', 'detail', 'message', 'bullet', 'item'] as $k) {
            if (isset($item[$k]) && is_string($item[$k])) {
                $t = trim($item[$k]);
                if ($t !== '') {
                    return $t;
                }
            }
        }
        foreach ($item as $v) {
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return '';
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function chatCompletionContent(string $model, array $messages, float $temperature): string
    {
        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];

        $ch = curl_init(self::CHAT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = $raw === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Chat request failed: ' . $curlErr);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Chat API error ' . $code . ': ' . substr($raw, 0, 2000));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid chat response');
        }
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (!is_string($content)) {
            throw new RuntimeException('Empty model content');
        }

        return $content;
    }
}
