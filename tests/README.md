# Tests

The tests mirror the modules: `tests/Modules/<Module>/` holds the tests of `modules/<Module>/`, and each module is one
PHPUnit suite (`phpunit.dist.xml`). They run against the `slam_test` database (`.env`, `database.tests.*`); never point
them at the real `slam` database.

## Running them

| What | Command | Time |
|---|---|---|
| Everything | `composer test` | about 30 s |
| The modules you changed (uncommitted work) | `composer test:changed` | a few seconds |
| The modules changed since a commit | `composer test:changed -- main~3` | |
| One module | `composer test -- --testsuite Schedule` | 4–8 s |
| Several modules | `composer test -- --testsuite PublicSite,Scoring` | |
| One test class or method | `composer test -- --filter ScheduleTest` · `--filter testReflowRemaining` | |
| With coverage (needs pcov) | `composer test:coverage` → `build/logs/html/index.html` | slower |

Suites: Accounts, Ads, Attendees, Checkin, Core, PublicSite, Reviews, Schedule, Scoring, Slams.

`test:changed` maps changed files to suites: a change in `modules/X/` or `tests/Modules/X/` runs suite X. A change
that every module depends on (`modules/Core`, `app/`, `tests/_support`, composer or PHPUnit config) runs everything.
Frontend-only changes run nothing (use `pnpm run build`).

## Why it is fast

- The test database is rebuilt from all migrations **once per run** (`SlamTestCase::setUpMigrate`), not before every
  test or every class. CodeIgniter's own `$migrateOnce` resets after each class, hence the override.
- Each test runs **inside a transaction that is rolled back** in `tearDown`, so every test still starts from a clean
  database. `Db::tx` uses a savepoint when a transaction is already open, so a refused action still undoes exactly
  its own writes, in tests and in production.
- Coverage is off unless asked for (`composer test:coverage`).

Consequences when writing tests: ids keep counting up between tests (never assert a literal id; use the ids the
fixtures return), and a query that lists rows must order them fully (add `id` as the last sort key) or the test
can be flaky.

## Adding a test

1. Put it in `tests/Modules/<Module>/<Thing>Test.php`, namespace `Tests\Modules\<Module>`.
2. Extend `Tests\Support\SlamTestCase` for anything touching the database (it freezes the clock at
   2026-11-01 03:00 UTC and has `makeSlam()`), or `CodeIgniter\Test\CIUnitTestCase` for pure logic.
3. Run `composer test:changed` while working and `composer test` before committing.

A new module gets its own `<testsuite>` in `phpunit.dist.xml`.

The load test (k6, about 1,000 phones) is separate: see `tests/load/README.md`.
