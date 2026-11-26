---
name: orb-setup
description: >-
  The shared toolchain setup for Amp orbs: `.agents/setup` installs PHP 8.4, Composer 2 and Node 22
  with every download verified, runs a repository's own `.agents/setup.pre.local` before it installs
  the locked dependencies and `.agents/setup.local` after, each when it exists; `.agents/resume`
  checks on wake that nothing needs installing and runs `.agents/resume.local`. The sync writes both files, executable, to a
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

## What the script needs before it starts

The script does not install these on a fresh machine (UAMS-Web/uams-claude-skills#927). Amp's pages,
read 2026-11-25, say the following; nothing here was measured on an orb.

| Needed | On an Amp-hosted orb | On a self-hosted orb whose image is built on another base |
| --- | --- | --- |
| Debian, with `apt-get` and `dpkg` | Debian 12 or 13 | Not required: x64 or ARM64 Linux with glibc. **Check for it.** |
| `curl` | Not in the list of included tools; the setup examples call it without installing it | Required only for CLI updates. **Check for it.** |
| `ca-certificates` | Not in the list of included tools | CA certificates are required of every orb |
| `sudo`, usable without a prompt | Not in the list of included tools; the setup examples call it without installing it, and the orb user can use `sudo` | Required of every orb, without a password |

The sources are [Customizing Orbs](https://ampcode.com/docs/orbs/customizing) and, for the last column, the
section *Bring Your Own Image* of [Self-Hosted Orbs](https://ampcode.com/docs/orbs/self-hosted). An image
that lacks `apt-get` cannot run the script. One that lacks `curl` stops at the sury.org keyring, which is
downloaded before any package is installed, so the `curl` entry in the package list cannot supply it there.
Add `curl` to that image. The `ca-certificates` entry is in the same position.

## What the shared setup does

1. Installs PHP 8.4 and its extensions from sury.org, with other system packages, among
   them `xz-utils`, which `tar` needs for the Node archive. The keyring is checked against
   a pinned sha256 before `dpkg -i`; a mismatch stops the run.
2. Installs Composer 2, checking the installer against `installer.sig`.
3. Installs Node 22, checking the archive against `SHASUMS256.txt`.
4. Runs `.agents/setup.pre.local` when it exists.
5. Runs `composer install` when `composer.json` exists and `npm ci` when `package-lock.json` exists.
6. Runs `.agents/setup.local` when it exists.

Every step is skipped when its result is already present, so a second run changes nothing.

## Repository-specific steps

Put them in `.agents/setup.local` (and wake checks in `.agents/resume.local`). They run with `bash`,
from the repository root, after the shared steps.

A step the dependency installs need first goes in `.agents/setup.pre.local` (UAMS-Web/uams-claude-skills#890).
It runs with `bash`, from the repository root, after PHP, Composer and Node are installed and before
`composer install` and `npm ci`, so the tools are on `PATH` and nothing from the lock files is installed
yet. Two cases need it:

- **An npm newer than the bundled one.** The shared Node ships its own npm, and a `package.json` whose
  `engines.npm` floor is higher, with `engine-strict=true` in `.npmrc`, makes `npm ci` refuse. Upgrade
  npm to that floor here, for example `sudo npm install -g npm@11.10.0`.
- **Files the Composer scripts read.** `composer install` runs the repository's Composer scripts, so
  anything they need (submodules, for example `git submodule update --init`) is prepared here.

A failing step stops setup before any dependency is installed. Without the file, setup runs exactly as
before. The sync never writes or deletes any of the three files.

## When the sury.org keyring changes

The check fails closed with both hashes in the message. Read the new keyring, then either run with
`SURY_KEYRING_SHA256=<new value>` once or update the pinned value in the template.

## Measured

The tests in `scripts/seeds.test.mjs` run both scripts under Git Bash on Windows against stub tools: nothing is downloaded when the tools are present, `.agents/setup.local` runs when present and is absent from the output when not, a failing local file fails setup, and a keyring whose checksum differs is never installed. Not measured: macOS (not a target for these files).

The `.agents/setup.pre.local` tests in the same file (UAMS-Web/uams-claude-skills#890) were run under bash 3.2.57 on macOS against the same stubs. They show that the file runs from the repository root before `composer install`, with `setup.local` still last. Without the file, nothing about it is printed. A failing file stops setup before any install. An npm stub that refuses `npm ci` below `>=11.10.0` passes once the file upgrades it and refuses without that upgrade, but that stub stands in for npm's own engine check, which was not run. Moving the hook after `composer install` fails the order test. Not yet measured: these tests under Git Bash on Windows, and a fresh orb.

`.agents/setup` was run whole, with real downloads, in Debian 12 and Debian 13 slim containers (arm64, Docker 29.5.2) that ran as root, had `sudo`, `curl` and `ca-certificates` added and had no `xz-utils`, from a directory with no `composer.json` or `package-lock.json` (UAMS-Web/uams-claude-skills#926). Before `xz-utils` was in the package list, both exited 2 at the Node step with `tar (child): xz: Cannot exec: No such file or directory`. With it, both exited 0 with Node v22.22.3. A run on Debian 13 after the failed one installed only `xz-utils`, and a second run installed no package. The `UAMS-Web/uams-claude-skills#926` test shows only that the script checks for `xz-utils` before it unpacks the archive; it was run under bash 3.2.57 on macOS, not yet under Git Bash on Windows. Not yet measured: x86_64, an ordinary user with `sudo`, and a provisioned orb.
