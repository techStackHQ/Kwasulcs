<?php

declare(strict_types=1);

// Hand-rolled .env loader — no Composer dependency needed since none
// exists in this project yet (see config.php's require_once for how this
// gets wired in). Parses simple KEY=VALUE lines: '#' comments and blank
// lines are skipped, and a value may be wrapped in single or double quotes
// to include leading/trailing spaces or a literal '#' if ever needed.
//
// A missing .env file is NOT an error — that's the expected, correct state
// on any host that provides these values via the real process environment
// instead (e.g. AlwaysData's dashboard has an "Environment variables"
// panel for exactly this — production should use that, not a .env file on
// disk).

/**
 * Loads $path into the process environment, WITHOUT overwriting a variable
 * that's already set there. That "don't overwrite" rule is deliberate: a
 * real hosting-panel env var must always win over whatever a stray .env
 * file on disk says, so the same code can't accidentally use stale file
 * contents on a host where the real variable is already correctly set.
 */
function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue; // malformed line — skip rather than fail the whole app
        }

        // Strip one layer of matching quotes, if the value has them.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) !== false) {
            continue; // real process env var already set — it wins
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

/**
 * Reads environment variable $key (process env first, then $_ENV as a
 * fallback for SAPIs where putenv()/getenv() don't share visibility),
 * returning $default when it was never set at all. An explicitly-set empty
 * string is a real value, not "unset" — it is returned as-is, not replaced
 * by $default.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? false;
    }
    return $value !== false ? $value : $default;
}
