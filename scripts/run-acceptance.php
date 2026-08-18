<?php
/**
 * Cross‑platform wrapper for acceptance tests.
 * Detects the operating system and runs the appropriate script:
 *   - Windows:  scripts\\run-acceptance.bat
 *   - Unix/macOS: scripts/run-acceptance.sh
 */

$osFamily = PHP_OS_FAMILY;
$extraArgs = array_slice($argv, 1);
$argString = !empty($extraArgs) ? ' ' . implode(' ', array_map('escapeshellarg', $extraArgs)) : '';

if ($osFamily === 'Windows') {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'run-acceptance.bat';
    $cmd = "cmd /c \"\"$script\"$argString\"";
} else {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'run-acceptance.sh';
    $cmd = "bash $script$argString";
}

// Execute the command and pass STDOUT/STDERR directly
$descriptorSpec = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR
];

$process = proc_open($cmd, $descriptorSpec, $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start acceptance test script.\n");
    exit(1);
}

$exitCode = proc_close($process);
exit($exitCode);
?>
