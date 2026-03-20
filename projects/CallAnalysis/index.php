<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/init.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ca_status_class(string $s): string
{
    return match ($s) {
        'ready' => 'ready',
        'failed' => 'failed',
        'pending', 'transcribing', 'analyzing' => 'pending',
        default => 'pending',
    };
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

/** Dashboard / UI label for stored sentiment (positive | neutral | negative). */
function ca_sentiment_label(?string $sentiment): string
{
    if ($sentiment === null || trim($sentiment) === '') {
        return '—';
    }
    $k = strtolower(trim($sentiment));

    return match ($k) {
        'positive' => 'Positive',
        'negative' => 'Negative',
        'neutral', 'natural' => 'Neutral',
        default => ucfirst($k),
    };
}

$baseUrl = '/projects/CallAnalysis';
$pdo = null;
$dbError = null;
try {
    $pdo = ca_db();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$schemaOk = false;
if ($pdo) {
    try {
        $pdo->query('SELECT 1 FROM ca_calls LIMIT 1');
        $schemaOk = true;
    } catch (Throwable) {
        $schemaOk = false;
    }
}

$stats = [];
$calls = [];
$keywords = [];
if ($pdo && $schemaOk) {
    $repo = new CallRepository($pdo);
    $stats = $repo->dashboardStats();
    $calls = $repo->listCalls(100);
    $keywords = $repo->aggregateTopKeywordCounts(10);
}

$total = (int) ($stats['total_calls'] ?? 0);
$avgDur = $stats['avg_duration'] ?? null;
$avgScore = $stats['avg_score'] ?? null;
$avgSentimentIndex = isset($stats['avg_sentiment_index']) && $stats['avg_sentiment_index'] !== null && $stats['avg_sentiment_index'] !== ''
    ? (float) $stats['avg_sentiment_index']
    : null;
$actionTotal = (int) ($stats['action_items_total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Analysis — Dashboard</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="<?= h($baseUrl) ?>/call-analysis.css">
</head>
<body class="ca-body ca-dashboard-page">
    <header class="header header--dense">
        <nav>
            <a href="/">Projects</a>
            <a href="<?= h($baseUrl) ?>/" class="active">Call Analysis</a>
        </nav>
    </header>

    <main class="ca-dashboard">
        <?php if ($dbError): ?>
            <div class="ca-dashboard__errs">
                <p class="error">Database error: <?= h($dbError) ?></p>
            </div>
        <?php elseif (!$schemaOk): ?>
            <div class="ca-dashboard__errs">
                <p class="error">Database tables are missing. Run once:
                    <code>docker compose exec fpm php /var/www/html/projects/CallAnalysis/db/install.php</code>
                    or open <a href="<?= h($baseUrl) ?>/db/install.php">db/install.php</a> in the browser.</p>
            </div>
        <?php else: ?>

            <div class="ca-dashboard__masthead">
                <h1 class="ca-dashboard__title">Commerce Pundit</h1>
            </div>

            <form class="ca-upload-banner" id="ca-upload-form" method="post" enctype="multipart/form-data" action="" aria-label="Upload call recording">
                <div class="ca-upload-banner__glow" aria-hidden="true"></div>
                <div class="ca-upload-banner__inner">
                    <div class="ca-upload-banner__head">
                        <div class="ca-upload-banner__brand" aria-hidden="true">
                            <span class="ca-upload-banner__bars">
                                <span></span><span></span><span></span><span></span>
                            </span>
                        </div>
                        <div class="ca-upload-banner__copy">
                            <h2 class="ca-upload-banner__title">Add a call recording</h2>
                            <p class="ca-upload-banner__hint">MP3, WAV, M4A, WebM, OGG · up to ~24 MB</p>
                        </div>
                    </div>
                    <div class="ca-upload-banner__file-area" id="ca-upload-file-area">
                        <label class="ca-upload-banner__drop" for="ca-recording-input">
                            <input
                                class="ca-upload-banner__input"
                                type="file"
                                id="ca-recording-input"
                                name="recording"
                                accept="audio/*,.mp3,.wav,.m4a,.webm,.ogg,.mp4,.mpeg,.mpga,.oga"
                                required
                            >
                            <span class="ca-upload-banner__drop-face">
                                <span class="ca-upload-banner__drop-icon" aria-hidden="true">
                                    <svg width="22" height="26" viewBox="0 0 22 26" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 1H5a3 3 0 0 0-3 3v18a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3V9l-8-8Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><path d="M12 1v8h8M7 15h8M7 19h5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
                                </span>
                                <span class="ca-upload-banner__drop-text" aria-live="polite">
                                    <span class="ca-upload-banner__file-name" id="ca-upload-file-name">No file selected</span>
                                    <span class="ca-upload-banner__file-hint" id="ca-upload-file-hint">Click here or use Browse to choose an audio file</span>
                                    <span class="ca-upload-banner__file-size" id="ca-upload-file-size" hidden></span>
                                </span>
                                <span class="ca-upload-banner__browse-pill">Browse…</span>
                            </span>
                        </label>
                        <button type="submit" class="ca-upload-banner__submit" id="ca-upload-submit">Upload &amp; queue</button>
                    </div>
                </div>
            </form>

            <div id="ca-upload-feedback" class="ca-upload-feedback" role="status" aria-live="polite" hidden></div>

            <script>
            (function () {
                var baseUrl = <?= json_encode($baseUrl, JSON_THROW_ON_ERROR) ?>;
                var uploadUrl = baseUrl + '/api/upload.php';
                var form = document.getElementById('ca-upload-form');
                var input = document.getElementById('ca-recording-input');
                var area = document.getElementById('ca-upload-file-area');
                var nameEl = document.getElementById('ca-upload-file-name');
                var hintEl = document.getElementById('ca-upload-file-hint');
                var sizeEl = document.getElementById('ca-upload-file-size');
                var submitBtn = document.getElementById('ca-upload-submit');
                var feedback = document.getElementById('ca-upload-feedback');
                if (!form || !input || !area || !nameEl || !hintEl || !sizeEl || !submitBtn || !feedback) return;

                function formatSize(n) {
                    if (typeof n !== 'number' || n < 0) return '';
                    if (n < 1024) return n + ' B';
                    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
                    return (n / 1048576).toFixed(2) + ' MB';
                }

                function syncFileDisplay() {
                    var f = input.files && input.files[0];
                    if (f) {
                        nameEl.textContent = f.name;
                        nameEl.title = f.name;
                        hintEl.hidden = true;
                        sizeEl.textContent = formatSize(f.size);
                        sizeEl.hidden = false;
                        area.classList.add('ca-upload-banner__file-area--selected');
                    } else {
                        nameEl.textContent = 'No file selected';
                        nameEl.removeAttribute('title');
                        hintEl.hidden = false;
                        sizeEl.textContent = '';
                        sizeEl.hidden = true;
                        area.classList.remove('ca-upload-banner__file-area--selected');
                    }
                }

                input.addEventListener('change', syncFileDisplay);
                input.addEventListener('input', syncFileDisplay);

                var feedbackHideTimer = null;
                var statusUrl = baseUrl + '/api/calls-status.php';
                var dashboardStatsUrl = baseUrl + '/api/dashboard-stats.php';

                function setFeedback(type, text) {
                    if (feedbackHideTimer) {
                        clearTimeout(feedbackHideTimer);
                        feedbackHideTimer = null;
                    }
                    feedback.hidden = false;
                    feedback.textContent = text;
                    feedback.className = 'ca-upload-feedback ca-upload-feedback--' + type;
                    if (type === 'success') {
                        feedbackHideTimer = setTimeout(function () {
                            feedbackHideTimer = null;
                            clearFeedback();
                        }, 3000);
                    }
                }

                function clearFeedback() {
                    if (feedbackHideTimer) {
                        clearTimeout(feedbackHideTimer);
                        feedbackHideTimer = null;
                    }
                    feedback.hidden = true;
                    feedback.textContent = '';
                    feedback.className = 'ca-upload-feedback';
                }

                function statusClass(status) {
                    if (status === 'ready') return 'ready';
                    if (status === 'failed') return 'failed';
                    return 'pending';
                }

                function isProcessingStatus(s) {
                    return s === 'pending' || s === 'transcribing' || s === 'analyzing';
                }

                function formatDurationSec(sec) {
                    if (sec == null || !isFinite(Number(sec))) {
                        return '—';
                    }
                    var s = Math.round(Number(sec));
                    var m = Math.floor(s / 60);
                    var r = s % 60;
                    if (m >= 60) {
                        var h = Math.floor(m / 60);
                        m = m % 60;
                        return h + ':' + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
                    }
                    return m + ':' + String(r).padStart(2, '0');
                }

                function setDecimalMetric(numId, sufId, value) {
                    var numEl = document.getElementById(numId);
                    var sufEl = sufId ? document.getElementById(sufId) : null;
                    if (!numEl) {
                        return;
                    }
                    if (value == null || !isFinite(Number(value))) {
                        numEl.textContent = '—';
                        if (sufEl) {
                            sufEl.hidden = true;
                        }
                    } else {
                        numEl.textContent = Number(value).toFixed(1);
                        if (sufEl) {
                            sufEl.hidden = false;
                        }
                    }
                }

                function renderKeywordCloud(keywords) {
                    var body = document.getElementById('ca-dash-keywords-body');
                    if (!body) {
                        return;
                    }
                    body.textContent = '';
                    var list = Array.isArray(keywords) ? keywords : [];
                    if (list.length === 0) {
                        var empty = document.createElement('span');
                        empty.className = 'ca-dash-keywords__empty';
                        empty.textContent = '— No keyword data yet';
                        body.appendChild(empty);
                        return;
                    }
                    list.forEach(function (item) {
                        var label = typeof item === 'string' ? item : (item && item.keyword != null ? String(item.keyword) : '');
                        var count = item && typeof item === 'object' && item.count != null ? Number(item.count) : NaN;
                        if (!label) {
                            return;
                        }
                        var sp = document.createElement('span');
                        sp.className = 'ca-dash-kw';
                        var lab = document.createElement('span');
                        lab.className = 'ca-dash-kw__label';
                        lab.textContent = label;
                        sp.appendChild(lab);
                        if (isFinite(count)) {
                            var cnt = document.createElement('span');
                            cnt.className = 'ca-dash-kw__count';
                            cnt.textContent = String(count);
                            cnt.title = 'Times this term appeared in analyzed calls';
                            sp.appendChild(cnt);
                        }
                        body.appendChild(sp);
                    });
                }

                function refreshDashboardMetrics() {
                    fetch(dashboardStatsUrl, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json().catch(function () { return null; }); })
                        .then(function (data) {
                            if (!data || !data.ok || !data.stats) {
                                return;
                            }
                            var s = data.stats;
                            var elTotal = document.getElementById('ca-dash-metric-total');
                            if (elTotal) {
                                elTotal.textContent = String(s.total_calls != null ? s.total_calls : 0);
                            }
                            setDecimalMetric('ca-dash-metric-sentiment-num', 'ca-dash-metric-sentiment-suf', s.avg_sentiment_index);
                            setDecimalMetric('ca-dash-metric-score-num', 'ca-dash-metric-score-suf', s.avg_score);
                            var elDur = document.getElementById('ca-dash-metric-duration');
                            if (elDur) {
                                elDur.textContent = formatDurationSec(s.avg_duration_seconds);
                            }
                            var elAct = document.getElementById('ca-dash-metric-actions');
                            if (elAct) {
                                elAct.textContent = String(s.action_items_total != null ? s.action_items_total : 0);
                            }
                            if (data.keywords) {
                                renderKeywordCloud(data.keywords);
                            }
                        })
                        .catch(function () { /* ignore */ });
                }

                function buildStatusCell(status) {
                    var td = document.createElement('td');
                    td.className = 'ca-call-row__status';
                    var badge = document.createElement('span');
                    badge.setAttribute('data-role', 'status-badge');
                    badge.className = 'ca-status ca-status--' + statusClass(status);
                    badge.textContent = status;
                    td.appendChild(badge);
                    var prog = document.createElement('div');
                    prog.className = 'ca-call-progress';
                    prog.setAttribute('data-role', 'status-progress');
                    prog.hidden = !isProcessingStatus(status);
                    var track = document.createElement('div');
                    track.className = 'ca-call-progress__track';
                    var fill = document.createElement('div');
                    fill.className = 'ca-call-progress__fill';
                    fill.setAttribute('data-phase', status);
                    track.appendChild(fill);
                    prog.appendChild(track);
                    td.appendChild(prog);
                    return td;
                }

                function formatSentimentLabel(s) {
                    if (s == null || String(s).trim() === '') {
                        return '—';
                    }
                    var v = String(s).trim().toLowerCase();
                    if (v === 'positive') {
                        return 'Positive';
                    }
                    if (v === 'negative') {
                        return 'Negative';
                    }
                    if (v === 'neutral' || v === 'natural') {
                        return 'Neutral';
                    }
                    return v.charAt(0).toUpperCase() + v.slice(1);
                }

                function updateCallRow(tr, c) {
                    var st = c.status || 'pending';
                    tr.dataset.status = st;
                    var badge = tr.querySelector('[data-role="status-badge"]');
                    var prog = tr.querySelector('[data-role="status-progress"]');
                    var fill = tr.querySelector('.ca-call-progress__fill');
                    if (badge) {
                        badge.textContent = st;
                        badge.className = 'ca-status ca-status--' + statusClass(st);
                    }
                    if (fill) {
                        fill.setAttribute('data-phase', st);
                    }
                    if (prog) {
                        prog.hidden = !isProcessingStatus(st);
                    }
                    var scoreTd = tr.querySelector('.ca-table__col-score');
                    var sentTd = tr.querySelector('.ca-table__col-sentiment');
                    if (scoreTd) {
                        scoreTd.textContent = c.overall_score != null ? Number(c.overall_score).toFixed(1) : '—';
                    }
                    if (sentTd) {
                        sentTd.textContent = formatSentimentLabel(c.sentiment);
                    }
                }

                function getPendingCallIds() {
                    var out = [];
                    document.querySelectorAll('#ca-recent-calls-body tr.ca-call-row[data-call-id]').forEach(function (tr) {
                        var s = tr.getAttribute('data-status') || '';
                        if (isProcessingStatus(s)) {
                            out.push(tr.getAttribute('data-call-id'));
                        }
                    });
                    return out;
                }

                function pollCallStatuses() {
                    var ids = getPendingCallIds();
                    if (ids.length === 0) {
                        return;
                    }
                    fetch(statusUrl + '?ids=' + encodeURIComponent(ids.join(',')), {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json().catch(function () { return null; }); })
                        .then(function (data) {
                            if (!data || !data.ok || !data.calls) {
                                return;
                            }
                            var needDashRefresh = false;
                            data.calls.forEach(function (c) {
                                var tr = document.querySelector('#ca-recent-calls-body tr.ca-call-row[data-call-id="' + String(c.id) + '"]');
                                if (!tr) {
                                    return;
                                }
                                var prev = tr.getAttribute('data-status') || '';
                                updateCallRow(tr, c);
                                var next = tr.getAttribute('data-status') || '';
                                if (isProcessingStatus(prev) && !isProcessingStatus(next)) {
                                    needDashRefresh = true;
                                }
                            });
                            if (needDashRefresh) {
                                refreshDashboardMetrics();
                            }
                        })
                        .catch(function () { /* ignore */ });
                }

                function startStatusPolling() {
                    pollCallStatuses();
                    setInterval(pollCallStatuses, 2800);
                }

                function prependCallRow(call) {
                    var tbody = document.getElementById('ca-recent-calls-body');
                    var table = document.getElementById('ca-recent-table');
                    var emptyEl = document.getElementById('ca-recent-empty');
                    if (!tbody || !table || !emptyEl) return;

                    emptyEl.hidden = true;
                    table.hidden = false;

                    var st = call.status || 'pending';
                    var tr = document.createElement('tr');
                    tr.className = 'ca-call-row';
                    tr.dataset.callId = String(call.id);
                    tr.dataset.status = st;

                    var tdId = document.createElement('td');
                    tdId.textContent = String(call.id);
                    tr.appendChild(tdId);

                    var tdFile = document.createElement('td');
                    tdFile.className = 'ca-table__file';
                    tdFile.textContent = call.original_filename || '';
                    tr.appendChild(tdFile);

                    tr.appendChild(buildStatusCell(st));

                    var tdScore = document.createElement('td');
                    tdScore.className = 'ca-table__col-score';
                    tdScore.textContent = call.overall_score != null ? Number(call.overall_score).toFixed(1) : '—';
                    tr.appendChild(tdScore);

                    var tdSent = document.createElement('td');
                    tdSent.className = 'ca-table__col-sentiment';
                    tdSent.textContent = formatSentimentLabel(call.sentiment);
                    tr.appendChild(tdSent);

                    var tdLink = document.createElement('td');
                    var a = document.createElement('a');
                    a.className = 'ca-table__link';
                    a.href = baseUrl + '/call.php?id=' + encodeURIComponent(String(call.id));
                    a.textContent = 'Open';
                    tdLink.appendChild(a);
                    tr.appendChild(tdLink);

                    tbody.insertBefore(tr, tbody.firstChild);
                }

                function resetAfterSuccess() {
                    input.value = '';
                    syncFileDisplay();
                }

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    clearFeedback();

                    if (!input.files || !input.files[0]) {
                        setFeedback('error', 'Please choose an audio file first.');
                        return;
                    }

                    var fd = new FormData(form);
                    var label = submitBtn.textContent;
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Uploading…';

                    fetch(uploadUrl, {
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
                                setFeedback('error', 'Invalid response from server. Try again.');
                                return;
                            }
                            if (data.ok) {
                                setFeedback('success', data.message || 'Recording queued.');
                                if (data.call) {
                                    prependCallRow(data.call);
                                    setTimeout(pollCallStatuses, 400);
                                }
                                resetAfterSuccess();
                            } else {
                                setFeedback('error', data.error || 'Upload failed.');
                            }
                        })
                        .catch(function () {
                            submitBtn.disabled = false;
                            submitBtn.textContent = label;
                            setFeedback('error', 'Network error. Check your connection and try again.');
                        });
                });

                startStatusPolling();
            })();
            </script>

            <div class="ca-dash-metrics" role="list">
                <div class="ca-dash-tile ca-dash-tile--indigo" role="listitem">
                    <span class="ca-dash-tile__label">Calls processed</span>
                    <span id="ca-dash-metric-total" class="ca-dash-tile__value"><?= h((string) $total) ?></span>
                </div>
                <div class="ca-dash-tile ca-dash-tile--rose" role="listitem">
                    <span class="ca-dash-tile__label">Avg. sentiment</span>
                    <span class="ca-dash-tile__value"><span id="ca-dash-metric-sentiment-num"><?= $avgSentimentIndex !== null ? h(number_format($avgSentimentIndex, 1)) : '—' ?></span><span id="ca-dash-metric-sentiment-suf" class="ca-dash-tile__suffix"<?= $avgSentimentIndex === null ? ' hidden' : '' ?>>/10</span></span>
                </div>
                <div class="ca-dash-tile ca-dash-tile--amber" role="listitem">
                    <span class="ca-dash-tile__label">Avg. score</span>
                    <span class="ca-dash-tile__value"><span id="ca-dash-metric-score-num"><?= $avgScore !== null ? h(number_format((float) $avgScore, 1)) : '—' ?></span><span id="ca-dash-metric-score-suf" class="ca-dash-tile__suffix"<?= $avgScore === null ? ' hidden' : '' ?>>/10</span></span>
                </div>
                <div class="ca-dash-tile ca-dash-tile--teal" role="listitem">
                    <span class="ca-dash-tile__label">Avg. duration</span>
                    <span id="ca-dash-metric-duration" class="ca-dash-tile__value"><?= $avgDur !== null ? h(ca_format_duration((float) $avgDur)) : '—' ?></span>
                </div>
                <div class="ca-dash-tile ca-dash-tile--emerald" role="listitem">
                    <span class="ca-dash-tile__label">Action items</span>
                    <span id="ca-dash-metric-actions" class="ca-dash-tile__value"><?= h((string) $actionTotal) ?></span>
                </div>
            </div>

            <section class="ca-dash-keywords" aria-labelledby="ca-kw-heading">
                <div class="ca-dash-keywords__head">
                    <h2 id="ca-kw-heading" class="ca-dash-keywords__title">Top keywords</h2>
                    <span class="ca-dash-keywords__sub">Top 10 by count across analyzed calls</span>
                </div>
                <div id="ca-dash-keywords-body" class="ca-dash-keywords__body"><?php
                    if ($keywords === []) {
                        echo '<span class="ca-dash-keywords__empty">— No keyword data yet</span>';
                    } else {
                        foreach ($keywords as $row) {
                            $kw = (string) ($row['keyword'] ?? '');
                            $cnt = (int) ($row['count'] ?? 0);
                            if ($kw === '') {
                                continue;
                            }
                            echo '<span class="ca-dash-kw"><span class="ca-dash-kw__label">' . h($kw) . '</span>'
                                . '<span class="ca-dash-kw__count" title="Times this term appeared in analyzed calls">' . h((string) $cnt) . '</span></span>';
                        }
                    }
                ?></div>
            </section>

            <section class="ca-dash-table-panel" aria-labelledby="ca-recent-heading">
                <div class="ca-dash-table-panel__head">
                    <h2 id="ca-recent-heading" class="ca-dash-table-panel__title">Recent calls</h2>
                </div>
                <div class="ca-dash-table-scroll">
                    <p id="ca-recent-empty" class="ca-dash-empty muted" <?= $calls === [] ? '' : 'hidden' ?>>No uploads yet — use the upload bar above.</p>
                    <table id="ca-recent-table" class="ca-table ca-table--dash" <?= $calls === [] ? 'hidden' : '' ?>>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>File</th>
                                <th>Status</th>
                                <th>Score</th>
                                <th>Sentiment</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="ca-recent-calls-body">
                            <?php foreach ($calls as $c):
                                $callStatus = (string) $c['status'];
                                $callBusy = in_array($callStatus, ['pending', 'transcribing', 'analyzing'], true);
                                ?>
                                <tr class="ca-call-row" data-call-id="<?= (int) $c['id'] ?>" data-status="<?= h($callStatus) ?>">
                                    <td><?= h((string) $c['id']) ?></td>
                                    <td class="ca-table__file"><?= h((string) $c['original_filename']) ?></td>
                                    <td class="ca-call-row__status">
                                        <span class="ca-status ca-status--<?= h(ca_status_class($callStatus)) ?>" data-role="status-badge"><?= h($callStatus) ?></span>
                                        <div class="ca-call-progress" data-role="status-progress" <?= $callBusy ? '' : 'hidden' ?>>
                                            <div class="ca-call-progress__track">
                                                <div class="ca-call-progress__fill" data-phase="<?= h($callStatus) ?>"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ca-table__col-score"><?= isset($c['overall_score']) && $c['overall_score'] !== null ? h(number_format((float) $c['overall_score'], 1)) : '—' ?></td>
                                    <td class="ca-table__col-sentiment"><?= h(ca_sentiment_label(isset($c['sentiment']) ? (string) $c['sentiment'] : null)) ?></td>
                                    <td><a class="ca-table__link" href="<?= h($baseUrl) ?>/call.php?id=<?= (int) $c['id'] ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php endif; ?>
    </main>
</body>
</html>
