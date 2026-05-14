# Drupal artifact builder

Helps generating artifacts for Drupal by wrapping all code into an artifact, and pushing it to the artifact remote repository.

## Installation

```bash
composer require metadrop/drupal-artifact-builder
```

### Configuration

Drupal artifact builder allow using a configuration file to create the artifact.
Artifacts usually are executed using always the same parameters. So, a configuration
file saves time adding those parameters every time the command is run.

Configuration file is placed at root (it can be changed through command line parameters). You cam copy the template
to have an starting point:

```
cp vendor/metadrop/drupal-artifact-builder/.drupal-artifact-builder.yml.dist .drupal-artifact-builder.yml
```

#### Configuration properties

- **commands**: Commands to run inside the artifact folder before packaging.

    Example:
    ```yaml
    commands:
        - composer install --no-dev
        - cd web/themes/custom/foo/ && npm install && npm run production
    ```

- **repository**: Repository URL (git SSH / git HTTP URL).

    Example:
    ```yaml
    repository: git@github.com:example/example-artifact.git
    ```

- **include**: Extra files or folders to include into the artifact.

    Example:
    ```yaml
    include:
      - oauth
      - solr
    ```

- **author**: It will be the author used in git commits.

    Example:
    ```yaml
    author: John Doe <passionate.developer@example.com>
    ```

- **branches_map**: Key value map to git push source artifact branches to different artifact branches.

    Example:
    ```yaml
    branches_map:
      develop:develop-build
    ```

This example will make push the artifacts coming from develop source branch to the develop-build artifact branch.

## Usage

**Important**: Please note that Drupal Artifact Builder does not download Composer libraries or compile CSS assets. These tasks must be completed prior to running the command.

Builds the artifact and push the changes to git:

```
drupal-artifact-builder
```

Generate the artifact:

```
drupal-artifact-builder create
```

Push the created artifact to git:

```
drupal-artifact-builder git
```

### Parameters

- **branch**: Branch to create the artifact from / to. Required.

- **config**: Allow setting the configuration file. Defaults to .drupal-artifact-builder.yml

    ```
    drupal-artifact-builder git  folder/.drupal-artifact-builder.custom.yml
    ```


- **repository**: Selects the repository where the artifacts will be pushed.

    Examples:

    For the complete command (create + git):
    ```
    drupal-artifact-builder --repository git@example.com:example/example.git
    ```

    For the git command:
    ```
    drupal-artifact-builder git --repository git@example.com:example/example.git
    ```


- **include**: Allow adding more paths to the artifact.

    ```
    drupal-artifact-builder --repository git@example.com:example/example.git --include=oauth.json,mycustomapp
    ```

### Artifact folder location

By default the artifact is built inside the system temporary directory (the value of `sys_get_temp_dir()`, typically `/tmp` on Linux). This means **you do not need to add the artifact folder to `.gitignore`**.

To use a different location, pass the `--artifact-folder` option:

```
drupal-artifact-builder --artifact-folder /custom/path/artifact
drupal-artifact-builder --artifact-folder relative/path/artifact
```

Relative paths are resolved from the Drupal project root. Absolute paths are used as-is.

In sandboxed CI environments where `/tmp` is not writable, override the base temp directory via the standard `TMPDIR` environment variable:

```
TMPDIR=/workspace/tmp drupal-artifact-builder
```

### Running without a source git repository

`drupal-artifact-builder create` normally relies on the source project being a git working tree. When run in an environment where the codebase is present on disk without a `.git` directory (for example, when it has been extracted from a tarball or a snapshot in a CI image), the command bootstraps a throwaway git repository in the source root so that the same `git archive` based pipeline can be used, and removes it after the artifact is generated.

Caveats in this mode:

- `--branch` is mandatory; auto-detection from the current branch is not possible.
- `hash.txt` cannot reference a real commit; it is filled with a `synthetic-YYYYMMDD-HHMMSS` placeholder.

## CI integration

### GitHub Actions

A ready-to-use workflow is available at [`examples_ci/github-actions.yml`](examples_ci/github-actions.yml). Copy it into your Drupal project at `.github/workflows/deploy-artifact.yml` and follow these steps:

1. **Generate an SSH key pair** for the artifact repository:

    ```bash
    ssh-keygen -t ed25519 -C "artifact-deploy" -f artifact_deploy_key -N ""
    ```

2. **Add the public key** (`artifact_deploy_key.pub`) as a deploy key with write access in the artifact repository settings (Settings > Deploy keys).

3. **Add the private key** (`artifact_deploy_key`) as a repository secret named `ARTIFACT_DEPLOY_KEY` in the source Drupal repository (Settings > Secrets and variables > Actions).

4. **Adjust the workflow** to your needs:
    - Update the `branches` list under `on.push` to match the branches you want to deploy.
    - Change the Docker image tag (`php8.3-node20`) to match your PHP and Node versions. Available tags are listed in the [drupal-artifact-builder-docker](https://github.com/metadrop/drupal-artifact-builder-docker) repository.

5. Make sure `.drupal-artifact-builder.yml` exists in your project root and has the `repository` key pointing to the artifact repository.

The workflow runs inside the [`ghcr.io/metadrop/drupal-artifact-builder-docker`](https://github.com/metadrop/drupal-artifact-builder-docker) image, which provides PHP, Composer, Node, and Git out of the box.

## Upgrade from 1.x to 2.x

2.0.0 release brings breaking changes and the way to use drupal-artifact-builder changes.

These steps must be followed in order to upgrade to the 2.0.0 version:

1. Copy and configure .drupal-artifact-builder.yml:

    ```
    cp vendor/metadrop/drupal-artifact-builder/.drupal-artifact-builder.yml.dist .drupal-artifact-builder.yml
    ```

2. Change --extra-paths parameters to --include

    Before:

    ```bash
    drupal-artifact-builder --extra-paths solr
   ```

   Now:

   ```bash
   drupal-artifact-builder --include solr
   ```

3. Stop using GIT_BRANCH environment variable, now it is --branch

   Before:

   ```bash
   GIT_BRANCH=develop drupal-artifact-builder
   ```

   Now:

   ```bash
   drupal-artifact-builder --branch develop
   ```

## Upgrade from 2.x to 3.x

To be able to bring composer libraries and compile the custom theme, configure `commands` in .drupal-artifact-builder.yml.

    Example:
    ```yaml
    commands:
        - composer install --no-dev
        - cd web/themes/custom/foo/ && npm install && npm run production
    ```
