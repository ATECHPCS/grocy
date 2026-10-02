<?php

declare(strict_types=1);

$command = escapeshellarg(PHP_BINARY) . ' -d disable_functions=pcntl_fork ' . escapeshellarg(__DIR__ . '/capture_research_queue.php') . ' 2>&1';
exec($command, $output, $status);
if ($status !== 1 || !str_contains(implode("\n", $output), 'requires pcntl_fork, pcntl_waitpid, pcntl_wexitstatus and Unix stream sockets'))
{
	fwrite(STDERR, "queue prerequisite diagnostic missing\n");
	exit(1);
}
echo "capture research queue prerequisites: PASS\n";
