# grouped-test-runner

A single-file PHP script that runs your test suite **one group at a time**, each
group in its own child process.

Why: on large Laravel / PHPUnit / Pest suites a single `php artisan test` run can
exhaust memory, and one crashing test aborts everything. Running per folder keeps
memory flat, isolates crashes, and tells you exactly which group failed and how
long each took.

## Install

Copy `test-groups.php` into your project root (next to `vendor/`). The only
requirement is `symfony/process`, which Laravel and most PHPUnit projects already
have. No framework is booted by the runner itself.

## Usage

Run from the project root:

```bash
php test-groups.php                     # run every discovered group
php test-groups.php --list              # dry run: show what would run / be skipped
php test-groups.php --fail-fast         # stop after the first failing group
php test-groups.php --only=Admin,Auth   # groups whose path contains "Admin" or "Auth"
php test-groups.php --only=tests/Feature/Admin/FooTest.php   # one file or nested folder
php test-groups.php --skip=Payment      # leave out matching groups
php test-groups.php --all               # ignore skip lists
php test-groups.php --roots=tests/Unit,tests/Feature
php test-groups.php --cmd="vendor/bin/pest"
php test-groups.php --help
```

A **group** is each sub-folder or loose `*Test.php` file directly under a root
(default roots: `tests/Unit` and `tests/Feature`). Groups are discovered
automatically, so new test folders are never silently left out.

`--only` accepts a mix: values that are existing paths run directly, other values
are substring filters. `--only` ignores the skip list.

### Test command

Auto-detected: `php artisan test` if `artisan` exists, else `vendor/bin/pest`,
else `vendor/bin/phpunit`. Override with `--cmd` or `"command"` in the config.

### Config file (optional)

Create `test-groups.json` in the project root (see `test-groups.example.json`):

```json
{
    "roots": ["tests/Unit", "tests/Feature"],
    "skip": ["tests/Feature/Payment"],
    "command": "php artisan test"
}
```

Command-line flags override the config file.

## Output

- Progress per group (`[3/22]`), pass/fail and duration
- A summary table with totals and elapsed time
- On failure: the details are printed and saved to
  `storage/logs/test-groups-<timestamp>.log` (or the project root if there is no
  `storage/logs`)

| Exit code | Meaning                      |
|-----------|------------------------------|
| 0         | all groups passed            |
| 1         | at least one group failed    |
| 2         | nothing matched / bad config |

## License

MIT
