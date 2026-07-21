<?php

declare(strict_types=1);

/**
 * Coverage ratchet: fails the build if line coverage drops below the
 * committed baseline. Coverage may only go up (or hold).
 *
 * Usage: php coverage-ratchet.php <clover.xml> <baseline-file>
 *
 * The baseline file holds a single float (percent). When a PR raises
 * coverage, bump the baseline in the same PR to lock in the gain.
 */

[$_, $cloverPath, $baselinePath] = $argv + [null, null, null];

if (!$cloverPath || !$baselinePath || !is_file($cloverPath)) {
    fwrite(STDERR, "usage: coverage-ratchet.php <clover.xml> <baseline-file>\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "could not parse {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];

$coverage = $statements > 0 ? round($covered / $statements * 100, 2) : 0.0;
$baseline = is_file($baselinePath) ? (float)trim((string)file_get_contents($baselinePath)) : 0.0;

// Small tolerance so unrelated refactors that shift the denominator by a
// fraction of a percent don't fail the build.
$tolerance = 0.10;

echo "### Backend line coverage\n\n";
echo "| Current | Baseline |\n|---|---|\n| {$coverage}% | {$baseline}% |\n\n";

if ($coverage + $tolerance < $baseline) {
    echo "❌ Coverage fell below the baseline. Add tests for the code you changed, ";
    echo "or (only with reviewer sign-off) lower `backend/coverage-baseline.txt`.\n";
    exit(1);
}

if ($coverage > $baseline) {
    echo "📈 Coverage is above the baseline — bump `backend/coverage-baseline.txt` to {$coverage} in this PR to lock it in.\n";
}

echo "✅ Ratchet satisfied.\n";
exit(0);
