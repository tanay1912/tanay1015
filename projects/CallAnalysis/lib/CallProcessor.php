<?php

declare(strict_types=1);

final class CallProcessor
{
    public function __construct(
        private CallRepository $repo,
        private OpenAIClient $openai,
    ) {}

    public function process(int $callId): void
    {
        $call = $this->repo->getCall($callId);
        if (!$call) {
            return;
        }

        $fullPath = CA_ROOT . '/' . $call['stored_path'];
        if (!is_readable($fullPath)) {
            $this->repo->updateStatus($callId, 'failed', 'Recording file missing');
            return;
        }

        $mime = $call['mime'] ?: 'application/octet-stream';

        try {
            $this->repo->updateStatus($callId, 'transcribing', null);
            $verbose = $this->openai->transcribeVerbose($fullPath, $mime, (string) $call['original_filename']);

            $this->repo->deleteSegments($callId);
            $idx = 0;
            foreach ($verbose['segments'] as $seg) {
                $this->repo->insertSegment(
                    $callId,
                    $idx++,
                    $seg['start'],
                    $seg['end'],
                    $seg['text']
                );
            }

            $dur = (float) ($verbose['duration'] ?? 0);
            if ($dur > 0) {
                $this->repo->setDuration($callId, $dur);
            }

            $transcriptText = implode(
                "\n",
                array_map(static fn (array $s): string => $s['text'], $verbose['segments'])
            );

            $this->repo->updateStatus($callId, 'analyzing', null);
            $analysis = $this->openai->analyzeTranscript($transcriptText);

            $this->repo->saveAnalysis($callId, $analysis);
            $this->repo->updateStatus($callId, 'ready', null);
        } catch (Throwable $e) {
            $this->repo->updateStatus($callId, 'failed', $e->getMessage());
        }
    }
}
