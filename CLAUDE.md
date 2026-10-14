# ArkHive: instructions for coding agents

- Laravel Zero CLI distributed as a PHAR. Entry point `arkhive`, commands in `app/Commands/` (thin: input, one use case, output and exit code). Layers: `app/Domain/` (pure models and rules, no I/O), `app/Application/` (use cases; ports in `app/Application/Port/`), `app/Infrastructure/` (adapters: restic, processes, HTTP, mail, console, logging, systemd). `tests/Architecture` enforces the direction of dependencies. Helpers in `app/helpers.php`.
- Current work: release 2.1 (systemd interoperability, journald/syslog logging, resilience, sysexits exit codes, Domain-Driven layout, restic compatibility from Debian 11). The plan is `docs/2.1-PLAN.md`; `docs/2.0-RESTIC-SPEC.md` remains the reference for everything the plan does not change. If something in either is wrong or impossible, say so in the PR description instead of working around it.
- The bar is a serious, dependable product: every behaviour in the spec is covered by tests; a feature without tests is not done.
- Runtime target PHP 8.4 (minimum 8.3: Laravel Zero 13). If `php -v` shows an older version, install 8.4 first (`ppa:ondrej/php`) and run everything with it.
- Tests: `vendor/bin/phpunit` (unit), `tests/e2e/run.sh` (Docker: SFTP server, MariaDB, PostgreSQL, fake releases server), distro matrix and quality gates as in spec §13. All relevant ones must pass before any commit that claims a feature done. Never test against a real host or the real GitHub Releases API.
- Interactive vs unattended (spec §11): never prompt, colour or draw progress when unattended; a missing answer in unattended mode is exit 2 naming the flag. Test both modes (pseudo-TTY via `script -qec` and no TTY).
- Portability (spec §12): prefer PHP functions over shell tools; no GNU-only flags; child processes with `LC_ALL=C`; missing dependencies reported with the distro's install command.
- Never write real hostnames, IPs, usernames, keys or passwords anywhere in the repo: this repository is public. Use `example.com` and placeholders.
- Releases: never create or push tags, never commit built PHARs. Releases are built by `.github/workflows/release.yml` when the maintainer pushes a `v*` tag.
- Commits: conventional (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`), English, one logical change each.
- Code style: PSR-12, `declare(strict_types=1)`, strict comparisons, typed parameters and returns, early returns; check every external process exit status; secrets never on a command line.
