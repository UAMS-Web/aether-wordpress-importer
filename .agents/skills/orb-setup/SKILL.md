---
name: orb-setup
description: >-
  The shared toolchain setup for Amp orbs: `.agents/setup` installs PHP 8.4, Composer 2 and Node 22
  with every download verified, installs the locked dependencies, and runs a repository's own
  `.agents/setup.local` when it exists; `.agents/resume` checks on wake that nothing needs
  installing and runs `.agents/resume.local`. The sync writes both files, executable, to a
  repository that takes the `orb` profile. Activate when adding or changing a repository's orb
  setup, moving a repository onto the shared script, or fixing an orb setup failure.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/orb-setup/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): orb.
-->
<!-- cspell:ignore sury dpkg SHASUMS -->

# Orb setup

The sync writes `.agents/setup` and `.agents/resume` to each repository that takes the `orb`
profile (UAMS-Web/uams-claude-skills#562). Both are seeds in `replace` mode with the `executable`
flag: the sync rewrites them whole whenever they differ and keeps them mode `0755`. Edit them in
`uams-claude-skills` under `shared/.claude/skills/orb-setup/templates/`, never in the repository.

## What the shared setup does

1. Installs PHP 8.4 and its extensions from sury.org. The keyring is checked against a pinned
   sha256 before `dpkg -i`; a mismatch stops the run.
2. Installs Composer 2, checking the installer against `installer.sig`.
3. Installs Node 22, checking the archive against `SHASUMS256.txt`.
4. Runs `composer install` when `composer.json` exists and `npm ci` when `package-lock.json` exists.
5. Runs `.agents/setup.local` when it exists.

Every step is skipped when its result is already present, so a second run changes nothing.

## Repository-specific steps

Put them in `.agents/setup.local` (and wake checks in `.agents/resume.local`). The sync never writes
or deletes those two files. They run with `bash`, from the repository root, after the shared steps.

## When the sury.org keyring changes

The check fails closed with both hashes in the message. Read the new keyring, then either run with
`SURY_KEYRING_SHA256=<new value>` once or update the pinned value in the template.

## Measured

The tests in `scripts/seeds.test.mjs` run both scripts under Git Bash on Windows against stub tools: nothing is downloaded when the tools are present, `.agents/setup.local` runs when present and is absent from the output when not, a failing local file fails setup, and a keyring whose checksum differs is never installed. Not measured: a run on a bare Linux orb, and macOS (not a target for these files).
