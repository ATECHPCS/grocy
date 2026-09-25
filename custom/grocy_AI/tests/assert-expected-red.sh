#!/usr/bin/env bash

set -u

usage()
{
	printf 'Usage: %s EXPECTED_MARKER [--cwd ABSOLUTE_DIRECTORY] -- command...\n' "$0" >&2
	exit 2
}

if [ "$#" -lt 3 ]
then
	usage
fi

expected_marker=$1
shift
command_cwd=''

if [ "${1:-}" = '--cwd' ]
then
	[ "$#" -ge 3 ] || usage
	command_cwd=$2
	shift 2
	case "$command_cwd" in
		/*) ;;
		*)
			printf 'Expected --cwd to be an absolute directory: %s\n' "$command_cwd" >&2
			exit 2
			;;
	esac
	if [ ! -d "$command_cwd" ]
	then
		printf 'Expected --cwd directory does not exist: %s\n' "$command_cwd" >&2
		exit 2
	fi
fi

[ "${1:-}" = '--' ] || usage
shift
[ "$#" -gt 0 ] || usage

output_file=$(mktemp "${TMPDIR:-/tmp}/grocy-ai-expected-red.XXXXXX") || exit 2
trap 'rm -f "$output_file"' EXIT HUP INT TERM

if [ -n "$command_cwd" ]
then
	(
		cd "$command_cwd" || exit 125
		"$@"
	) >"$output_file" 2>&1
	command_status=$?
else
	"$@" >"$output_file" 2>&1
	command_status=$?
fi

if [ "$command_status" -ne 1 ]
then
	printf 'Expected an assertion-failure exit code (1), received %s.\n' "$command_status" >&2
	cat "$output_file" >&2
	exit 1
fi

marker_count=$(grep -Fxc -- "$expected_marker" "$output_file" || true)
if [ "$marker_count" -ne 1 ]
then
	printf 'Expected exactly one standalone RED marker: %s\n' "$expected_marker" >&2
	cat "$output_file" >&2
	exit 1
fi

unexpected_marker_count=$(grep -E '^EXPECTED_RED: ' "$output_file" | grep -Fvx -- "$expected_marker" | wc -l | tr -d ' ')
if [ "$unexpected_marker_count" -ne 0 ]
then
	printf 'A different RED marker was emitted.\n' >&2
	cat "$output_file" >&2
	exit 1
fi

if grep -Eiq '(^|[^[:alpha:]])(parse error|syntaxerror|syntax error|fatal error|importerror|modulenotfounderror|cannot find module|no tests? found|did not find any test|fixture (server|asset).*(failed|unavailable)|web server.*failed|browser.*(failed to launch|not installed)|executable does not exist|unauthorized|not authenticated|http (401|403)|missing dependency|dependency.*(missing|not found))' "$output_file"
then
	printf 'Rejected infrastructure, syntax, discovery, authentication, browser-launch, fixture, or dependency failure.\n' >&2
	cat "$output_file" >&2
	exit 1
fi

printf '%s\n' "$expected_marker"
