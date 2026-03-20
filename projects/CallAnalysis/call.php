<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/init.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ca_format_duration(float $sec): string
{
    $s = (int) round($sec);
    $m = intdiv($s, 60);
    $r = $s % 60;
    if ($m >= 60) {
        $h = intdiv($m, 60);
        $m = $m % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $r);
    }
    return sprintf('%d:%02d', $m, $r);
}

/** @return array<string, string> */
function ca_dimension_labels(): array
{
    return [
        'communication_clarity' => 'Communication clarity',
        'politeness' => 'Politeness',
        'business_knowledge' => 'Business knowledge',
        'problem_handling' => 'Problem handling',
        'listening_ability' => 'Listening ability',
    ];
}

/** @return array<string, string> */
function ca_dimension_hints(): array
{
    return [
        'communication_clarity' => 'Was the agent clear, concise, and easy to understand throughout the call?',
        'politeness' => 'Was the tone consistently respectful, empathetic, and professional?',
        'business_knowledge' => 'Did the agent show strong product and industry knowledge when answering questions?',
        'problem_handling' => 'Were objections handled calmly, logically, and constructively?',
        'listening_ability' => 'Did the agent give the customer space and opportunity to speak?',
    ];
}

/** @return list<string> */
function ca_questionnaire_default_topics(): array
{
    return [
        'Budget Discussion',
        'Competitor Comparison',
        'Kitchen Size / Scope',
        'Cabinet Style Preference',
        'Remodeling Full Kitchen?',
    ];
}

/**
 * Normalize topic labels so JSON from the model still matches our fixed list
 * (e.g. "Remodeling Full Kitchen" vs "Remodeling Full Kitchen?").
 */
function ca_questionnaire_topic_key(string $topic): string
{
    $s = mb_strtolower(trim($topic));
    $s = str_replace('?', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);

    return trim($s);
}

/**
 * Interpret model/DB values for "was this discovery topic addressed?".
 */
function ca_coerce_asked_bool(mixed $v): bool
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
 * @param list<array<string, mixed>>|null $fromJson
 * @return list<array{topic: string, asked: bool}>
 */
function ca_merge_questionnaire_coverage(array $defaultTopics, ?array $fromJson): array
{
    $map = [];
    if (is_array($fromJson) && $fromJson !== []) {
        foreach ($fromJson as $row) {
            if (!is_array($row)) {
                continue;
            }
            $t = trim((string) ($row['topic'] ?? ''));
            if ($t === '') {
                continue;
            }
            $key = ca_questionnaire_topic_key($t);
            if (array_key_exists('asked', $row)) {
                $map[$key] = ca_coerce_asked_bool($row['asked']);
            } else {
                $map[$key] = false;
            }
        }
    }
    $out = [];
    foreach ($defaultTopics as $topic) {
        $k = ca_questionnaire_topic_key($topic);
        /* Missing / empty JSON / unmatched topic → No, not "Not assessed" */
        $out[] = [
            'topic' => $topic,
            'asked' => $map[$k] ?? false,
        ];
    }

    return $out;
}

/** @param list<mixed> $items */
function ca_parse_string_list(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (is_string($item)) {
            $t = trim($item);
            if ($t !== '') {
                $out[] = $t;
            }
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        foreach (['text', 'observation', 'point', 'note', 'detail', 'message', 'bullet', 'item'] as $k) {
            if (isset($item[$k]) && is_string($item[$k])) {
                $t = trim($item[$k]);
                if ($t !== '') {
                    $out[] = $t;
                    continue 2;
                }
            }
        }
        foreach ($item as $v) {
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '') {
                    $out[] = $t;
                    break;
                }
            }
        }
    }

    return $out;
}

$baseUrl = '/projects/CallAnalysis';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(302);
    header('Location: ' . $baseUrl . '/');
    exit;
}

$pdo = ca_db();
$repo = new CallRepository($pdo);
$call = $repo->getCall($id);
if (!$call) {
    http_response_code(404);
    exit('Call not found');
}

$segments = $repo->getSegments($id);
$analysis = $repo->getAnalysis($id);
$agentScores = $repo->getAgentScores($id);
$actions = $repo->getActionItems($id);

$status = (string) $call['status'];
$caProcessingStatuses = ['pending', 'transcribing', 'analyzing'];
$caRequeuePoll = isset($_GET['requeue']) && (string) $_GET['requeue'] === '1'
    && in_array($status, $caProcessingStatuses, true);
$caDetailRefreshSeconds = $caRequeuePoll ? 5 : 8;
$audioUrl = $baseUrl . '/audio.php?id=' . $id;

$dimLabels = ca_dimension_labels();
$dimHints = ca_dimension_hints();
$scoresByKey = [];
foreach ($agentScores as $row) {
    $scoresByKey[(string) $row['dimension_key']] = $row;
}

$keywords = [];
if ($analysis && array_key_exists('keywords_json', $analysis) && $analysis['keywords_json'] !== null && $analysis['keywords_json'] !== '') {
    $k = ca_decode_json_column($analysis['keywords_json']);
    if (is_array($k)) {
        $keywords = $k;
    }
}
$shifts = [];
if ($analysis && array_key_exists('conversation_shifts_json', $analysis) && $analysis['conversation_shifts_json'] !== null && $analysis['conversation_shifts_json'] !== '') {
    $k = ca_decode_json_column($analysis['conversation_shifts_json']);
    if (is_array($k)) {
        $shifts = $k;
    }
}
$signals = [];
if ($analysis && array_key_exists('patterns_json', $analysis) && $analysis['patterns_json'] !== null && $analysis['patterns_json'] !== '') {
    $k = ca_decode_json_column($analysis['patterns_json']);
    if (is_array($k)) {
        $signals = $k;
    }
}

$questionnaireRows = [];
$qcRaw = null;
if ($analysis && array_key_exists('questionnaire_coverage_json', $analysis) && $analysis['questionnaire_coverage_json'] !== null && $analysis['questionnaire_coverage_json'] !== '') {
    $qcRaw = ca_decode_json_column($analysis['questionnaire_coverage_json']);
}
$questionnaireRows = ca_merge_questionnaire_coverage(ca_questionnaire_default_topics(), is_array($qcRaw) ? $qcRaw : null);
$questionnaireStoredEmpty = !$analysis
    || !array_key_exists('questionnaire_coverage_json', $analysis)
    || $analysis['questionnaire_coverage_json'] === null
    || $analysis['questionnaire_coverage_json'] === ''
    || !is_array($qcRaw)
    || $qcRaw === [];

$topDiscussed = [];
if ($analysis && array_key_exists('top_discussed_json', $analysis) && $analysis['top_discussed_json'] !== null && $analysis['top_discussed_json'] !== '') {
    $td = ca_decode_json_column($analysis['top_discussed_json']);
    if (is_array($td)) {
        foreach ($td as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $emoji = trim((string) ($row['emoji'] ?? ''));
            $topDiscussed[] = [
                'emoji' => $emoji !== '' ? mb_substr($emoji, 0, 8) : '💬',
                'label' => $label,
            ];
        }
    }
}
if ($topDiscussed === [] && $keywords !== []) {
    foreach (array_slice($keywords, 0, 8) as $kw) {
        if (!is_string($kw) || trim($kw) === '') {
            continue;
        }
        $topDiscussed[] = ['emoji' => '💬', 'label' => trim($kw)];
    }
}

$positiveObs = [];
$negativeObs = [];
if ($analysis && array_key_exists('positive_observations_json', $analysis) && $analysis['positive_observations_json'] !== null && $analysis['positive_observations_json'] !== '') {
    $po = ca_decode_json_column($analysis['positive_observations_json']);
    if (is_array($po)) {
        $positiveObs = ca_parse_string_list($po);
    }
}
if ($analysis && array_key_exists('negative_observations_json', $analysis) && $analysis['negative_observations_json'] !== null && $analysis['negative_observations_json'] !== '') {
    $no = ca_decode_json_column($analysis['negative_observations_json']);
    if (is_array($no)) {
        $negativeObs = ca_parse_string_list($no);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call #<?= h((string) $id) ?> — Call Analysis</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="<?= h($baseUrl) ?>/call-analysis.css">
    <?php if (in_array($status, $caProcessingStatuses, true)): ?>
        <meta http-equiv="refresh" content="<?= (int) $caDetailRefreshSeconds ?>">
    <?php endif; ?>
</head>
<body class="ca-body ca-detail-page">
    <header class="header header--dense">
        <nav>
            <a href="/">Projects</a>
            <a href="<?= h($baseUrl) ?>/" class="active">Call Analysis</a>
        </nav>
        <span class="domain">Call #<?= h((string) $id) ?></span>
    </header>

    <main class="ca-detail">
        <div class="ca-detail-hero">
            <a class="ca-detail-back" href="<?= h($baseUrl) ?>/">← Dashboard</a>
            <h1 class="ca-detail-title"><?= h((string) $call['original_filename']) ?></h1>
            <div class="ca-detail-meta">
                <span class="ca-pill ca-pill--status ca-status ca-status--<?= h(match ($status) {
                    'ready' => 'ready',
                    'failed' => 'failed',
                    default => 'pending',
                }) ?>"><?= h($status) ?></span>
                <?php if ($call['duration_seconds'] !== null): ?>
                    <span class="ca-pill">Duration <?= h(ca_format_duration((float) $call['duration_seconds'])) ?></span>
                <?php endif; ?>
                <?php if ($analysis && $analysis['overall_score'] !== null): ?>
                    <span class="ca-pill ca-pill--score">Score <?= h(number_format((float) $analysis['overall_score'], 1)) ?>/10</span>
                <?php endif; ?>
            </div>
            <?php if ($status === 'ready'): ?>
                <div class="ca-detail-hero-actions">
                    <form class="ca-reanalyze-form" method="post" action="<?= h($baseUrl) ?>/reanalyze.php">
                        <input type="hidden" name="call_id" value="<?= (int) $id ?>">
                        <button type="submit" class="ca-reanalyze-btn">Re-run full analysis</button>
                    </form>
                    <?php if ($analysis): ?>
                        <form id="ca-email-summary-form" class="ca-email-summary-form" method="post" action="<?= h($baseUrl) ?>/api/send-summary-email.php" autocomplete="on">
                            <input type="hidden" name="call_id" value="<?= (int) $id ?>">
                            <label class="ca-email-summary-label" for="ca-email-summary-input">Email summary</label>
                            <input
                                type="email"
                                id="ca-email-summary-input"
                                name="email"
                                class="ca-email-summary-input"
                                placeholder="name@example.com"
                                inputmode="email"
                                autocomplete="email"
                                required
                            >
                            <button type="submit" class="ca-email-summary-btn" id="ca-email-summary-submit">Send</button>
                        </form>
                        <p id="ca-email-summary-feedback" class="ca-email-summary-feedback" hidden role="status"></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if (isset($_GET['requeue']) && $_GET['requeue'] === '1'): ?>
                    <p class="ca-alert ca-alert--info ca-detail-requeue-msg">Analysis queued. This page refreshes every 5 seconds until processing finishes.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($status === 'failed' && !empty($call['error_message'])): ?>
            <div class="ca-alert ca-alert--error"><?= h((string) $call['error_message']) ?></div>
        <?php endif; ?>

        <div class="ca-call-layout">
            <div class="ca-call-main">
                <?php if ($analysis): ?>
                    <div class="ca-detail-overview">
                        <section class="ca-card ca-card--panel">
                            <h2 class="ca-card__title">Call summary</h2>
                            <p class="ca-prose ca-prose--compact"><?= nl2br(h((string) ($analysis['summary'] ?? ''))) ?></p>
                            <dl class="ca-dl ca-dl--compact">
                                <dt>Purpose</dt>
                                <dd><?= h((string) ($analysis['purpose'] ?? '—')) ?></dd>
                                <dt>Main topics</dt>
                                <dd><?= h((string) ($analysis['main_topics'] ?? '—')) ?></dd>
                                <dt>Outcome</dt>
                                <dd><?= h((string) ($analysis['outcome'] ?? '—')) ?></dd>
                            </dl>
                        </section>
                        <section class="ca-card ca-card--panel">
                            <h2 class="ca-card__title">Sentiment &amp; conversation quality</h2>
                            <p class="ca-sentiment-badge ca-sentiment-badge--compact ca-sentiment-badge--<?= h((string) ($analysis['sentiment'] ?? 'neutral')) ?>">
                                <?= h(ucfirst((string) ($analysis['sentiment'] ?? 'neutral'))) ?>
                            </p>
                            <?php if (!empty($analysis['sentiment_rationale'])): ?>
                                <p class="muted ca-muted-tight"><?= h((string) $analysis['sentiment_rationale']) ?></p>
                            <?php endif; ?>
                            <div class="ca-mini-scores ca-mini-scores--compact">
                                <div><span class="ca-mini-scores__label">Pacing</span>
                                    <strong><?= $analysis['quality_pacing'] !== null ? h(number_format((float) $analysis['quality_pacing'], 1)) : '—' ?></strong> / 10</div>
                                <div><span class="ca-mini-scores__label">Structure</span>
                                    <strong><?= $analysis['quality_structure'] !== null ? h(number_format((float) $analysis['quality_structure'], 1)) : '—' ?></strong> / 10</div>
                                <div><span class="ca-mini-scores__label">Engagement</span>
                                    <strong><?= $analysis['quality_engagement'] !== null ? h(number_format((float) $analysis['quality_engagement'], 1)) : '—' ?></strong> / 10</div>
                            </div>
                            <?php if (!empty($analysis['quality_notes'])): ?>
                                <p class="ca-prose ca-prose--compact muted"><?= nl2br(h((string) $analysis['quality_notes'])) ?></p>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endif; ?>

                <section class="ca-card ca-card--panel ca-player-card">
                    <h2 class="ca-card__title">Recording and transcript</h2>
                    <?php if (is_readable(CA_ROOT . '/' . $call['stored_path'])): ?>
                        <audio id="ca-audio" class="ca-audio" controls preload="metadata" src="<?= h($audioUrl) ?>"></audio>
                    <?php else: ?>
                        <p class="error">Audio file missing.</p>
                    <?php endif; ?>

                    <?php if ($segments === []): ?>
                        <p class="muted">Transcript will appear after transcription completes.</p>
                    <?php else: ?>
                        <div id="ca-transcript" class="ca-transcript" role="log" aria-live="polite">
                            <?php foreach ($segments as $s): ?>
                                <span
                                    class="ca-transcript__seg"
                                    data-start="<?= h((string) $s['start_sec']) ?>"
                                    data-end="<?= h((string) $s['end_sec']) ?>"
                                ><?= h((string) $s['text']) ?> </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($analysis): ?>
                    <div class="ca-detail-pair" aria-label="Discovery coverage and topics">
                        <section class="ca-card ca-card--panel">
                            <h2 class="ca-card__title">Business Questionnaire Coverage</h2>
                            <?php if ($questionnaireStoredEmpty): ?>
                                <p class="muted ca-section-note">No questionnaire data is stored for this call yet (column empty or <code>[]</code>). After saving analysis, values show as Yes or No. Use <strong>Re-run full analysis</strong> above if this call was processed before questionnaire data was persisted.</p>
                            <?php endif; ?>
                            <div class="ca-table-scroll">
                                <table class="ca-questionnaire-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Question Topic</th>
                                            <th scope="col">Discussed?</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($questionnaireRows as $qr): ?>
                                            <tr>
                                                <td><?= h($qr['topic']) ?></td>
                                                <td>
                                                    <?php if ($qr['asked']): ?>
                                                        <span class="ca-yn ca-yn--yes"><span aria-hidden="true">✅</span> Yes</span>
                                                    <?php else: ?>
                                                        <span class="ca-yn ca-yn--no"><span aria-hidden="true">❌</span> No</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="ca-card ca-card--panel">
                            <h2 class="ca-card__title">Top Keywords Discussed</h2>
                            <?php if ($topDiscussed === []): ?>
                                <p class="muted">No keyword highlights for this call yet.</p>
                            <?php else: ?>
                                <ul class="ca-top-keywords">
                                    <?php foreach ($topDiscussed as $td): ?>
                                        <li class="ca-top-keyword-pill">
                                            <span class="ca-top-keyword-pill__emo" aria-hidden="true"><?= h($td['emoji']) ?></span>
                                            <span class="ca-top-keyword-pill__txt"><?= h($td['label']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                    </div>

                    <?php if ($signals !== [] || $shifts !== []): ?>
                        <section class="ca-card ca-card--panel">
                            <h2 class="ca-card__title">Patterns and shifts</h2>
                            <div class="ca-patterns-split<?= ($signals !== [] && $shifts !== []) ? ' ca-patterns-split--two' : '' ?>">
                                <?php if ($signals !== []): ?>
                                    <div class="ca-patterns-split__col">
                                        <h3 class="ca-subh ca-subh--tight">Behavioral signals</h3>
                                        <ul class="ca-bullet-list ca-bullet-list--tight"><?php foreach ($signals as $sig): ?>
                                            <li><?= h(is_string($sig) ? $sig : json_encode($sig)) ?></li>
                                        <?php endforeach; ?></ul>
                                    </div>
                                <?php endif; ?>
                                <?php if ($shifts !== []): ?>
                                    <div class="ca-patterns-split__col">
                                        <h3 class="ca-subh ca-subh--tight">Notable shifts</h3>
                                        <ul class="ca-bullet-list ca-bullet-list--tight"><?php foreach ($shifts as $sh): ?>
                                            <li><?= h(is_string($sh) ? $sh : json_encode($sh)) ?></li>
                                        <?php endforeach; ?></ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="ca-card ca-card--panel" aria-label="Follow-up action items">
                        <h2 class="ca-card__title">Follow-Up Action Items</h2>
                        <?php if ($actions === []): ?>
                            <p class="muted">No action items detected for this call.</p>
                        <?php else: ?>
                            <ul class="ca-action-arrow-list">
                                <?php foreach ($actions as $a): ?>
                                    <li><?= h((string) $a['item_text']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="ca-call-side">
                <div class="ca-side-panel">
                    <?php if ($analysis): ?>
                        <h2 class="ca-side-title">Talk time analysis</h2>
                        <p class="ca-side-note">Estimated from transcript (LLM-assisted; not true speaker diarization).</p>
                        <div class="ca-bar-split">
                            <?php
                            $ap = (float) ($analysis['agent_talk_pct'] ?? 0);
                            $cp = (float) ($analysis['customer_talk_pct'] ?? 0);
                            if ($ap + $cp < 1) {
                                $ap = 50;
                                $cp = 50;
                            }
                            $sum = $ap + $cp;
                            if ($sum > 0) {
                                $ap = round(100 * $ap / $sum, 1);
                                $cp = round(100 * $cp / $sum, 1);
                            }
                            ?>
                            <div class="ca-bar-split__fill" style="--agent-pct: <?= h((string) $ap) ?>%">
                                <span class="ca-bar-split__agent"><?= h((string) $ap) ?>% agent</span>
                                <span class="ca-bar-split__cust"><?= h((string) $cp) ?>% customer</span>
                            </div>
                        </div>

                        <h2 class="ca-side-title ca-side-h2">Overall call score</h2>
                        <div class="ca-big-score">
                            <?= $analysis['overall_score'] !== null ? h(number_format((float) $analysis['overall_score'], 1)) : '—' ?>
                            <span class="ca-big-score__max">/ 10</span>
                        </div>

                        <h2 class="ca-side-title ca-side-h2">Agent performance</h2>
                        <p class="ca-side-note">Five dimensions scored 1–10 against QA criteria.</p>
                        <ul class="ca-dim-list">
                            <?php foreach ($dimLabels as $key => $label): ?>
                                <?php
                                $row = $scoresByKey[$key] ?? null;
                                $sc = $row ? (int) $row['score'] : null;
                                $meterPct = $sc !== null ? min(100, max(0, $sc * 10)) : 0;
                                ?>
                                <li class="ca-dim">
                                    <div class="ca-dim__head">
                                        <span class="ca-dim__name"><?= h($label) ?></span>
                                        <span class="ca-dim__score"><?= $sc !== null ? h((string) $sc) . ' / 10' : '—' ?></span>
                                    </div>
                                    <?php if (!empty($dimHints[$key])): ?>
                                        <p class="ca-dim__criteria"><?= h($dimHints[$key]) ?></p>
                                    <?php endif; ?>
                                    <?php if ($sc !== null): ?>
                                        <div class="ca-dim__meter" role="presentation" aria-hidden="true"><span style="width: <?= h((string) $meterPct) ?>%"></span></div>
                                    <?php endif; ?>
                                    <?php if ($row && !empty($row['justification'])): ?>
                                        <p class="ca-dim__why"><?= h((string) $row['justification']) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="ca-ai-notes ca-ai-notes--side" aria-label="AI-generated observations">
                            <h2 class="ca-side-title ca-side-h2">AI-generated notes</h2>
                            <p class="ca-ai-notes__disclaimer ca-ai-notes__disclaimer--side">Positive / negative bullets are model-generated from this transcript only.</p>
                            <div class="ca-ai-notes__block">
                                <h3 class="ca-ai-notes__sub">Positive Observations</h3>
                                <?php if ($positiveObs === []): ?>
                                    <p class="ca-ai-notes__empty">No AI-generated items stored (e.g. this call was analyzed before observations were required). Re-process the recording to fill this section.</p>
                                <?php else: ?>
                                    <ul class="ca-ai-notes__list">
                                        <?php foreach ($positiveObs as $line): ?>
                                            <li><?= h($line) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <div class="ca-ai-notes__block">
                                <h3 class="ca-ai-notes__sub">Negative Observations</h3>
                                <?php if ($negativeObs === []): ?>
                                    <p class="ca-ai-notes__empty">No AI-generated items stored (e.g. this call was analyzed before observations were required). Re-process the recording to fill this section.</p>
                                <?php else: ?>
                                    <ul class="ca-ai-notes__list">
                                        <?php foreach ($negativeObs as $line): ?>
                                            <li><?= h($line) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="ca-side-note">Analytics appear when this call finishes processing.</p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </main>

    <script>
    (function () {
        var audio = document.getElementById('ca-audio');
        var segs = document.querySelectorAll('.ca-transcript__seg');
        if (!audio || !segs.length) return;

        function tick() {
            var t = audio.currentTime;
            var active = null;
            segs.forEach(function (el) {
                var s = parseFloat(el.getAttribute('data-start'), 10);
                var e = parseFloat(el.getAttribute('data-end'), 10);
                var on = t >= s && t < e;
                el.classList.toggle('ca-transcript__seg--active', on);
                if (on) active = el;
            });
            if (active && !audio.paused) {
                var tr = document.getElementById('ca-transcript');
                if (tr) {
                    var er = tr.getBoundingClientRect();
                    var ar = active.getBoundingClientRect();
                    if (ar.top < er.top + 40 || ar.bottom > er.bottom - 40) {
                        active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                }
            }
        }

        audio.addEventListener('timeupdate', tick);
        audio.addEventListener('seeked', tick);
        segs.forEach(function (el) {
            el.addEventListener('click', function () {
                var s = parseFloat(el.getAttribute('data-start'), 10);
                if (!isNaN(s)) {
                    audio.currentTime = s;
                    audio.play().catch(function () {});
                }
            });
        });
    })();
    </script>
    <?php if ($status === 'ready' && $analysis): ?>
    <script>
    (function () {
        var form = document.getElementById('ca-email-summary-form');
        var feedback = document.getElementById('ca-email-summary-feedback');
        var submitBtn = document.getElementById('ca-email-summary-submit');
        if (!form || !feedback || !submitBtn) {
            return;
        }
        var sendUrl = <?= json_encode($baseUrl . '/api/send-summary-email.php', JSON_UNESCAPED_UNICODE) ?>;

        function setFeedback(type, text) {
            feedback.hidden = false;
            feedback.textContent = text;
            feedback.className = 'ca-email-summary-feedback ca-email-summary-feedback--' + type;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            var label = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending…';
            feedback.hidden = true;

            fetch(sendUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (data) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = label;
                    if (!data) {
                        setFeedback('error', 'Invalid response from server.');
                        return;
                    }
                    if (data.ok) {
                        setFeedback('success', data.message || 'Summary sent.');
                    } else {
                        setFeedback('error', data.error || 'Could not send email.');
                    }
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = label;
                    setFeedback('error', 'Network error. Try again.');
                });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
