## v0.70.4 - 2026-08-15
- Fix [fee formula] bracket-placeholder parsing; guard @eval against fatal ParseError on malformed formulas; add robustness test suite (334 tests total).

## v0.70.3 - 2026-08-14
- Bug fixes and minor updates.

## v0.70.2 - 2026-08-14
- Bug fixes and minor updates.

## v0.70.1 - 2026-08-14
- Bug fixes and minor updates.

## v0.70.0 - 2026-08-14
- Bug fixes and minor updates.

## v0.69.1 - 2026-08-14
- Bug fixes and minor updates.

## v0.69.0 - 2026-08-14
- Bug fixes and minor updates.

## v0.68.1 - 2026-08-14
- Bug fixes and minor updates.

## v0.68.0 - 2026-08-14
- Bug fixes and minor updates.

## v0.67.4 - 2026-08-14
- Bug fixes and minor updates.

## v0.67.3 - 2026-08-14
- Bug fixes and minor updates.

## v0.67.2 - 2026-08-13
- Bug fixes and minor updates.

## v0.67.1 - 2026-08-13
- Bug fixes and minor updates.

## v0.67.0 - 2026-08-13
- Bug fixes and minor updates.

## v0.66.0 - 2026-08-13
- Bug fixes and minor updates.

## v0.65.1 - 2026-08-13
- Bug fixes and minor updates.

## v0.65.0 - 2026-08-13
- Bug fixes and minor updates.

## v0.63.0 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.9 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.8 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.7 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.6 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.5 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.4 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.3 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.2 - 2026-08-13
- Bug fixes and minor updates.

## v0.62.1 - 2026-08-13
- Bug fixes and minor updates.

## v0.64.0 - 2026-08-13
- New feature additions.

# Changelog

## v0.62.0 - 2026-08-12
- Fixed JavaScript syntax error in Section 2 (`buildLocMainRow` unquoted string concatenation) blocking creation of new location, user & environment rules.
- Migrated plugin versioning system to standard 3-digit SemVer (`major.feature.patch`).
- Updated `build.ps1` script to match MBF Pack and Ship architecture with full SemVer bumping support (`-BumpMajor`, `-BumpMinor`, `-BumpPatch`), dry-run mode (`-DryRun`), automatic `CHANGELOG.md` updating, PHP syntax linting, and automatic local WordPress sync (`c:\xampp\htdocs\wp\wp-content\plugins\formula-rate-shipping`).
