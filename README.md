# Drupal artifact builder

Helps generating artifacts for Drupal

## Installation

```bash
composer require metadrop/drupal-artifact-builder
```

## Usage

Builds the artifact and push the changes to git:

```
drupal-artifact-builder --repository git@example.com:example/example.git
```

Only generates the artifact:

```
drupal-artifact-builder create
```

Only track the changes into git (it is needed to generate the artifact first):

```
drupal-artifact-builder git --repository git@example.com:example/example.git
```

### Parameters

- **extra-paths**: Allow adding more paths to the artifact.

```
drupal-artifact-builder --repository git@example.com:example/example.git --extra-paths=oauth.json,mycustomapp
```
