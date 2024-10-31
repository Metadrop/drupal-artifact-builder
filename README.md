# Drupal artifact builder

Helps generating artifacts for Drupal

## Installation

```bash
composer require metadrop/drupal-artifact-builder
```

### Configuration

Drupal artifact builder allow using a configuraiton file to create the artifact.
Artifacts usually are executed using always the same parameters. So, a configuration
file saves time adding those parameters every time the command is run.

Configuration file is placed at root (it can be changed through command line parameters). You cam copy the template
to have an starting point:

```
cp vendor/metadrop/drupal-artifact-builder/.drupal-artifact-builder.yml.dist .drupal-artifact-builder.yml
```

#### Configuration properties

- **repository**: Repository URL (git SSH / git HTTP URL).

    Example:
    ```yaml
    repository: git@github.com:example/example-artifact.git
    ```

- **include**: Extra files or folders to include into the artifact.

    Example:
    ```yaml
    include: []
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

3. Stop using GIT_BRANCH environment variable, not it is --branch

   Before:

   ```bash
   GIT_BRANCH=develop drupal-artifact-builder
   ```

   Now:

   ```bash
   drupal-artifact-builder --branch develop
   ```
