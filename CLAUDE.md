# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`drupal-artifact-builder` is a Composer library and CLI tool (PHP) that generates deployment artifacts for Drupal projects. It copies the compiled codebase into a separate folder (`deploy-artifact/`) and pushes it to a git repository.

## Setup

```bash
composer install
```

No test suite exists in this project.

## CLI entry point

The binary is `drupal-artifact-builder` (the file at the repo root). It registers three Symfony Console commands:

- `build` (default): runs `create` then `git`
- `create`: copies codebase files into `deploy-artifact/`
- `git`: clones the artifact repo, copies `.git` into `deploy-artifact/`, commits, and pushes

## Architecture

```
drupal-artifact-builder   # PHP CLI binary
src/
  BaseCommand.php          # Shared logic: config loading, assertRootLocation, assertArtifactContentIsClean, runCommand
  DrupalArtifactBuilderBuild.php   # build command - orchestrates create + git
  DrupalArtifactBuilderCreate.php  # create command - copies files to deploy-artifact/
  DrupalArtifactBuilderGit.php     # git command - clones artifact repo, commits, pushes
  Config/
    Config.php             # Holds repository, include, author, branch, branches_map; can load from YAML
    ConfigInterface.php
    ConfigurableInterface.php
```

Config is loaded once in `BaseCommand::initialize()` from `.drupal-artifact-builder.yml` (default) and shared across commands via `ConfigurableInterface`. When `DrupalArtifactBuilderBuild` runs sub-commands, it passes config through `setConfiguration()` so all three commands share the same `Config` instance.

The tool must be run from the Drupal project root. It validates presence of `docroot/` or `web/`, `config/`, and `composer.json` before doing anything.

Uses SOLID principles to implement all the logic.

## Key constants (BaseCommand)

- `ARTIFACT_FOLDER = 'deploy-artifact'`: output folder built relative to cwd
- `ARTIFACT_REPOSITORY_FOLDER = 'deploy-artifact-repository'`: temporary clone used to get the `.git` directory

## Configuration file

`.drupal-artifact-builder.yml` (copy from `.drupal-artifact-builder.yml.dist`). Supported keys:

- `repository`: git SSH/HTTP URL
- `include`: array of extra paths to copy into the artifact
- `author`: git commit author string
- `branches_map`: map of source branch → artifact branch (e.g. `develop: develop-build`)

Command-line options (`--repository`, `--include`, `--branch`, `--author`) override config file values.
