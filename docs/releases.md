# Release Guide

This document explains how to cut a new release of `hansajith18/laravel-paycorp`.

---

## How Versioning Works

Packagist reads versions from **Git tags** only. There must be **no `"version"` field** in `composer.json` — Packagist ignores it and prefers tags.

Follow [Semantic Versioning](https://semver.org):

| Change type | Version bump | Example |
|-------------|-------------|---------|
| Bug fix, security patch | **patch** | `1.0.0` → `1.0.1` |
| New backward-compatible feature | **minor** | `1.0.1` → `1.1.0` |
| Breaking change | **major** | `1.1.0` → `2.0.0` |

Tags must be prefixed with `v`: `v1.0.0`, `v1.1.0`, `v2.0.0`.

---

## Pre-Release Checklist

Before tagging, run these checks locally:

```bash
# 1. Make sure you are on main and up to date
git checkout main
git pull origin main

# 2. Run the test suite — must be green
composer test

# 3. Run static analysis
composer analyse

# 4. Format code (commit any changes it makes)
composer format
```

---

## Cutting a Release

```bash
# 1. Update CHANGELOG.md
#    - Move items from [Unreleased] into a new [vX.Y.Z] - YYYY-MM-DD section
#    - Leave a fresh empty [Unreleased] section at the top

# 2. Commit the changelog
git add CHANGELOG.md
git commit -m "docs: update changelog for vX.Y.Z"

# 3. Push main
git push origin main

# 4. Tag the release (lightweight tag is fine; annotated is preferred)
git tag vX.Y.Z

# 5. Push the tag — this triggers the GitHub Release workflow automatically
git push origin vX.Y.Z
```

After the tag push:
- GitHub Actions (`.github/workflows/release.yml`) creates a GitHub Release with auto-generated release notes.
- Packagist picks up the new tag via webhook and makes the new version installable within seconds.

---

## What the CI Workflows Do

| Workflow | File | Trigger | Purpose |
|----------|------|---------|---------|
| **Tests** | `.github/workflows/tests.yml` | Push / PR to `main` | Runs the full test matrix (PHP 8.2 + 8.3 × Laravel 10, 11, 12, 13) |
| **Release** | `.github/workflows/release.yml` | Push of a `v*.*.*` tag | Creates a GitHub Release with auto-generated notes |

---

## Patch Release Example

```bash
git checkout main
git pull origin main
# fix the bug, commit it
git add .
git commit -m "fix: correct HMAC encoding for UTF-8 payloads"
# update changelog
git add CHANGELOG.md
git commit -m "docs: update changelog for v1.0.1"
git push origin main
git tag v1.0.1
git push origin v1.0.1
```

---

## Major Release / Breaking Change

When making breaking changes:

1. Increment the major version: `v2.0.0`
2. Update `composer.json` `require` constraints for new minimum versions if needed
3. Document migration steps clearly in `CHANGELOG.md` under a `### Changed` or `### Removed` heading
4. Consider keeping a `1.x` branch for security backports

---

## Useful Commands

```bash
# List all existing tags
git tag -l

# Delete a tag locally and remotely (if you tagged too early)
git tag -d vX.Y.Z
git push origin --delete vX.Y.Z
```
