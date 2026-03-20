<?php
/**
 * Project hub: lists all projects in the projects/ folder.
 * Click a project to open it at /projects/<name>/
 */

$projectsDir = __DIR__ . '/projects';
$projects = [];

if (is_dir($projectsDir)) {
    foreach (new DirectoryIterator($projectsDir) as $entry) {
        if ($entry->isDir() && !$entry->isDot() && $entry->getFilename()[0] !== '.') {
            $name = $entry->getFilename();
            $indexPath = $projectsDir . '/' . $name . '/index.php';
            if (file_exists($indexPath)) {
                $projects[] = $name;
            }
        }
    }
    sort($projects);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects — local.vibecode.com</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="hub-page">
    <header class="header">
        <nav>
            <a href="/" class="active">Projects</a>
            <a href="http://localhost:8080" target="_blank" rel="noopener">Adminer</a>
            <a href="http://localhost:1080" target="_blank" rel="noopener">Mailcatcher</a>
        </nav>
        <span class="domain">local.vibecode.com</span>
    </header>

    <main class="main main--hub">
        <div class="hub-hero">
            <h1>Projects</h1>
            <p class="muted">Open an app from the list below.</p>
        </div>

        <?php if (empty($projects)): ?>
            <p class="empty">No projects yet. Add a folder with an <code>index.php</code> inside <code>projects/</code>.</p>
        <?php else: ?>
            <ul class="project-list">
                <?php foreach ($projects as $name): ?>
                    <li>
                        <a href="/projects/<?= htmlspecialchars($name) ?>/" class="project-card">
                            <div>
                                <span class="project-card__name"><?= htmlspecialchars($name) ?></span>
                                <?php if ($name === 'CallAnalysis'): ?>
                                    <div class="project-card__meta">Call recording intelligence · upload, transcribe, analyze</div>
                                <?php else: ?>
                                    <div class="project-card__meta">/projects/<?= htmlspecialchars($name) ?>/</div>
                                <?php endif; ?>
                            </div>
                            <span class="project-card__arrow" aria-hidden="true">→</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>

    <footer class="footer">
        PHP <?= PHP_VERSION ?> · <a href="http://localhost:8080" target="_blank" rel="noopener">Adminer</a> · <a href="http://localhost:1080" target="_blank" rel="noopener">Mailcatcher</a>
    </footer>
</body>
</html>
