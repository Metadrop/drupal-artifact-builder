<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates an artifact from a site already that is already setup.
 */
class DrupalArtifactBuilderCreate extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->setDescription('Creates an artifact from the current project.');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output): void {
    parent::initialize($input, $output);
    $this->syntheticGit = !$this->isGitRepository();
    if ($this->syntheticGit && empty($input->getOption('branch'))) {
      throw new \RuntimeException('--branch is required when running outside a git repository.');
    }
    $branch = $this->getBranch($input);
    $this->getConfiguration()->setBranch($branch);
    $this->log(sprintf('Target artifact branch: %s', $this->getConfiguration()->getBranch()));
    if ($this->syntheticGit) {
      $this->log('No git repository detected in source root — will bootstrap a temporary one for artifact generation.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->generateArtifact();
    return Command::SUCCESS;
  }

  /**
   * Tracks per-submodule absolute paths where this command bootstrapped a
   * throwaway .git directory. Used to scope cleanup to repos we created.
   *
   * @var string[]
   */
  protected array $bootstrappedSubmoduleGits = [];

  /**
   * Generates the artifact.
   *
   * @throws \Exception
   */
  protected function generateArtifact() {
    $rootBootstrapped = FALSE;
    if ($this->syntheticGit) {
      $excludes = [];
      if (file_exists($this->rootFolder . '/.gitmodules')) {
        $excludes = array_keys($this->getSubmodulePathsFromGitmodules());
      }
      $this->bootstrapSyntheticGit($this->rootFolder, $this->getConfiguration()->getBranch(), $excludes);
      $rootBootstrapped = TRUE;
    }

    try {
      $this->createArtifactFolder();
      $this->cleanArtifactFolder();
      $this->checkoutBranchInArtifact();
      $this->generateHashFile();
      $this->runPreArtifactCommands();
      $this->removeAllGitFolders();
      $this->removeGitIgnore();
      $this->generateGitIgnore();
      $this->cleanIgnoredFilesFromArtifact();
      $this->log('Artifact generated successfully');
    }
    finally {
      foreach ($this->bootstrappedSubmoduleGits as $path) {
        $this->teardownSyntheticGit($path);
      }
      $this->bootstrappedSubmoduleGits = [];
      if ($rootBootstrapped) {
        $this->teardownSyntheticGit($this->rootFolder);
      }
    }
  }

  /**
   * Initializes a throwaway git repo in $folder so git-archive based steps work.
   *
   * @param string $folder
   *   Absolute path of the folder to turn into a git repo.
   * @param string $branch
   *   Branch name to use for the initial commit.
   * @param string[] $excludePaths
   *   Repository-relative paths that must not be added to the initial commit
   *   (typically submodule paths, so they are archived separately).
   */
  protected function bootstrapSyntheticGit(string $folder, string $branch, array $excludePaths = []) {
    $this->log(sprintf('Bootstrapping temporary git repository in %s (branch %s)', $folder, $branch));

    // Prefer `git init -b` (Git >= 2.28); fall back to a post-init checkout.
    try {
      $this->runCommandInFolder(sprintf('git init -b %s', escapeshellarg($branch)), $folder);
    }
    catch (\Exception $e) {
      $this->runCommandInFolder('git init', $folder);
      $this->runCommandInFolder(sprintf('git checkout -b %s', escapeshellarg($branch)), $folder);
    }

    $pathspecs = '.';
    foreach ($excludePaths as $excludePath) {
      $pathspecs .= ' ' . escapeshellarg(':(exclude)' . trim($excludePath, '/'));
    }
    $this->runCommandInFolder(sprintf('git add -- %s', $pathspecs), $folder);

    $commitCommand = 'git -c user.email=artifact-builder@local -c user.name="Artifact Builder" '
      . 'commit -m "Synthetic artifact-builder commit" --allow-empty --no-verify';
    $this->runCommandInFolder($commitCommand, $folder);
  }

  /**
   * Removes a .git directory previously created by bootstrapSyntheticGit().
   *
   * Idempotent: missing .git is treated as success.
   *
   * @param string $folder
   *   Absolute path whose .git should be removed.
   */
  protected function teardownSyntheticGit(string $folder) {
    $this->log(sprintf('Removing temporary git repository in %s', $folder));
    $this->runCommand(sprintf('rm -rf %s/.git', escapeshellarg($folder)));
  }

  /**
   * Returns the raw list of submodule paths declared in .gitmodules.
   *
   * Unlike getArtifactSubmodulePaths(), this does not intersect with the
   * artifact whitelist — it is used to exclude every declared submodule from
   * the root synthetic commit.
   *
   * @return array<string, true>
   *   Map of submodule path => TRUE.
   */
  protected function getSubmodulePathsFromGitmodules(): array {
    $output = trim($this->runCommand('git config --file .gitmodules --get-regexp \'\.path$\'')->getOutput());
    if (empty($output)) {
      return [];
    }
    $paths = [];
    foreach (explode("\n", $output) as $line) {
      $parts = preg_split('/\s+/', trim($line), 2);
      $submodulePath = trim($parts[1] ?? '');
      if (!empty($submodulePath)) {
        $paths[$submodulePath] = TRUE;
      }
    }
    return $paths;
  }

  /**
   * Creates the artifact folder if it does not exist.
   */
  protected function createArtifactFolder() {
    $this->log('Creating artifact folder');
    $this->runCommand(sprintf('mkdir -p %s', $this->getArtifactFolder()));
  }

  /**
   * Removes all content from the artifact folder.
   */
  protected function cleanArtifactFolder() {
    $this->log('Cleaning previous artifact content');
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $this->runCommand(sprintf('rm -rf %s', $artifactPath));
    $this->runCommand(sprintf('mkdir -p %s', $artifactPath));
  }

  /**
   * Archives the target branch from origin into the artifact folder via git archive.
   *
   * No .git directory is copied; files are extracted directly from the git object store.
   */
  protected function checkoutBranchInArtifact() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $branch = $this->getConfiguration()->getBranch();

    $this->log(sprintf('Archiving branch "%s" into artifact folder', $branch));

    if ($this->syntheticGit) {
      // No remote in synthetic mode; archive directly from the local branch.
      $treeish = $branch;
    }
    else {
      try {
        $this->runCommand(sprintf('git fetch origin %s', escapeshellarg($branch)));
      }
      catch (\Exception $e) {
        $this->output->writeln(sprintf('<warning>[!] git fetch failed, artifact may not reflect the latest remote state: %s</warning>', $e->getMessage()));
      }

      $treeish = 'origin/' . $branch;
      try {
        $this->runCommand(sprintf('git rev-parse --verify %s', escapeshellarg($treeish)));
      }
      catch (\Exception $e) {
        $this->log(sprintf('Branch "origin/%s" not found in remote, using local branch', $branch));
        $treeish = $branch;
      }
    }

    $this->runCommand(sprintf(
      'git archive %s | tar -xC %s',
      escapeshellarg($treeish),
      escapeshellarg($artifactPath)
    ));

    if (file_exists($this->rootFolder . '/.gitmodules')) {
      $submodulesToArchive = $this->getArtifactSubmodulePaths();
      if (!empty($submodulesToArchive)) {
        if ($this->syntheticGit) {
          $this->archiveSubmodulesSynthetic($submodulesToArchive, $artifactPath);
        }
        else {
          $this->archiveSubmodulesFromRealRepo($submodulesToArchive, $artifactPath);
        }
      }
    }
  }

  /**
   * Archives submodules using the parent repo's submodule plumbing.
   *
   * @param array<string, string[]> $submodulesToArchive
   *   Map of submodule path => list of subpaths (or [] for whole submodule).
   * @param string $artifactPath
   *   Absolute path of the artifact root.
   */
  protected function archiveSubmodulesFromRealRepo(array $submodulesToArchive, string $artifactPath) {
    $this->log('Initializing git submodules included in the artifact');
    $pathArgs = implode(' ', array_map('escapeshellarg', array_keys($submodulesToArchive)));
    $this->runCommand(sprintf('git submodule update --init -- %s', $pathArgs));
    $this->runCommand(sprintf('git submodule update --recursive -- %s', $pathArgs));
    $submoduleAbsPaths = trim($this->runCommand('git submodule foreach --quiet --recursive pwd')->getOutput());
    foreach (explode("\n", $submoduleAbsPaths) as $submoduleAbsPath) {
      $submoduleAbsPath = trim($submoduleAbsPath);
      if (empty($submoduleAbsPath)) {
        continue;
      }
      $submoduleRelPath = ltrim(str_replace($this->rootFolder, '', $submoduleAbsPath), '/');
      $submoduleArtifactPath = $artifactPath . '/' . $submoduleRelPath;
      $this->runCommand(sprintf('mkdir -p %s', escapeshellarg($submoduleArtifactPath)));

      $subpaths = $submodulesToArchive[$submoduleRelPath] ?? [];
      $pathspec = '';
      if (!empty($subpaths)) {
        $pathspec = ' -- ' . implode(' ', array_map('escapeshellarg', $subpaths));
      }
      $this->runCommandInFolder(
        sprintf('git archive HEAD%s | tar -xC %s', $pathspec, escapeshellarg($submoduleArtifactPath)),
        $submoduleAbsPath
      );
    }
  }

  /**
   * Archives submodules when the parent root has no real .git.
   *
   * Skips `git submodule update --init` (there is no submodule config in
   * .git/config and no remote to fetch from). For each submodule directory
   * present on disk, uses `git archive HEAD` directly if the directory is a
   * working git repo; otherwise bootstraps a per-submodule throwaway git so
   * the same archive pipeline still applies.
   *
   * @param array<string, string[]> $submodulesToArchive
   *   Map of submodule path => list of subpaths (or [] for whole submodule).
   * @param string $artifactPath
   *   Absolute path of the artifact root.
   */
  protected function archiveSubmodulesSynthetic(array $submodulesToArchive, string $artifactPath) {
    $this->log('Archiving submodules without git submodule update (synthetic git mode)');
    foreach ($submodulesToArchive as $submoduleRelPath => $subpaths) {
      $submoduleAbsPath = $this->rootFolder . '/' . $submoduleRelPath;
      if (!is_dir($submoduleAbsPath)) {
        $this->output->writeln(sprintf(
          '<warning>[!] Submodule path "%s" is not present on disk; skipping.</warning>',
          $submoduleRelPath
        ));
        continue;
      }

      $isWorkingRepo = trim(
        $this->runCommandInFolder('git rev-parse --is-inside-work-tree 2>/dev/null || true', $submoduleAbsPath)->getOutput()
      ) === 'true';

      if (!$isWorkingRepo) {
        $submoduleGitPath = $submoduleAbsPath . '/.git';
        if (is_file($submoduleGitPath)) {
          // Stale submodule gitlink pointing at a parent .git that no longer
          // exists; safe to remove (it is a 1-line "gitdir:" pointer file).
          $this->log(sprintf('Removing stale submodule gitlink at %s', $submoduleGitPath));
          $this->runCommand(sprintf('rm -f %s', escapeshellarg($submoduleGitPath)));
        }
        elseif (is_dir($submoduleGitPath)) {
          throw new \RuntimeException(sprintf(
            'Submodule "%s" contains a .git directory but is not a working git repo. Refusing to remove it. Resolve the corruption manually and re-run.',
            $submoduleRelPath
          ));
        }
        $this->bootstrapSyntheticGit($submoduleAbsPath, 'main');
        $this->bootstrappedSubmoduleGits[] = $submoduleAbsPath;
      }

      $submoduleArtifactPath = $artifactPath . '/' . $submoduleRelPath;
      $this->runCommand(sprintf('mkdir -p %s', escapeshellarg($submoduleArtifactPath)));

      $pathspec = '';
      if (!empty($subpaths)) {
        $pathspec = ' -- ' . implode(' ', array_map('escapeshellarg', $subpaths));
      }
      $this->runCommandInFolder(
        sprintf('git archive HEAD%s | tar -xC %s', $pathspec, escapeshellarg($submoduleArtifactPath)),
        $submoduleAbsPath
      );
    }
  }

  /**
   * Writes hash.txt to docroot using the original repo commit hash.
   *
   * Must run before .git is removed from the artifact folder.
   */
  protected function generateHashFile() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    if ($this->syntheticGit) {
      $hash = 'synthetic-' . date('Ymd-His');
      $this->log(sprintf('Generated hash.txt with synthetic placeholder (no source git repo): %s', $hash));
    }
    else {
      $hash = trim($this->runCommand('git rev-parse HEAD')->getOutput());
      $this->log(sprintf('Generated hash.txt: %s', $hash));
    }
    file_put_contents($artifactPath . '/' . $this->calculateDocrootFolder() . '/hash.txt', $hash . PHP_EOL);
  }

  /**
   * Removes all .git folders from the artifact (including top-level and nested).
   */
  protected function removeAllGitFolders() {
    $this->log('Removing .git folders from artifact');
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $this->runCommandInFolder('find . -name ".git" -exec rm -rf {} +', $artifactPath);
  }

  /**
   * Removes the project .gitignore from the artifact.
   */
  protected function removeGitIgnore() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $this->runCommand(sprintf('rm -f %s/.gitignore', $artifactPath));
  }

  /**
   * Runs configured pre-artifact commands inside the artifact folder.
   */
  protected function runPreArtifactCommands() {
    $commands = $this->getConfiguration()->getCommands();
    if (empty($commands)) {
      return;
    }
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    foreach ($commands as $command) {
      $this->log(sprintf('Running pre-artifact command: %s', $command));
      $this->runCommandInFolder($command, $artifactPath);
    }
  }

  /**
   * Generates the artifact .gitignore with base patterns plus include/exclude config.
   */
  protected function generateGitIgnore() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $docroot = $this->calculateDocrootFolder();

    $patterns = $this->getBaseGitIgnorePatterns($docroot);

    $whitelist = array_merge($this->getWhitelistPaths(), ['.gitignore']);

    // Ignore every root entry whose top-level segment is not whitelisted.
    $topLevelKeep = [];
    foreach ($whitelist as $path) {
      $segments = explode('/', $path);
      $topLevelKeep[$segments[0]] = TRUE;
    }
    $artifactRootEntries = array_filter(
      scandir($artifactPath),
      fn($entry) => !in_array($entry, ['.', '..', '.git'])
    );
    foreach ($artifactRootEntries as $entry) {
      if (!isset($topLevelKeep[$entry])) {
        $patterns[] = '/' . $entry;
      }
    }

    // For each deep whitelist path, emit the gitignore ladder so every
    // ancestor stays reachable but siblings at each level are ignored.
    $emitted = [];
    foreach ($whitelist as $path) {
      $segments = explode('/', $path);
      if (count($segments) < 2) {
        continue;
      }
      $prefix = '';
      for ($i = 0, $n = count($segments) - 1; $i < $n; $i++) {
        $prefix .= '/' . $segments[$i];
        $ignoreSiblings = $prefix . '/*';
        $unignoreChild = '!' . $prefix . '/' . $segments[$i + 1];
        if (!isset($emitted[$ignoreSiblings])) {
          $patterns[] = $ignoreSiblings;
          $emitted[$ignoreSiblings] = TRUE;
        }
        if (!isset($emitted[$unignoreChild])) {
          $patterns[] = $unignoreChild;
          $emitted[$unignoreChild] = TRUE;
        }
      }
    }

    foreach ($this->getConfiguration()->getExclude() as $excludePath) {
      $patterns[] = $excludePath;
    }

    // For include entries that point at a file, an explicit negation is
    // needed because the ladder above only re-includes directory segments.
    foreach ($this->getConfiguration()->getInclude() as $includePath) {
      $relative = ltrim($includePath, '/');
      if (is_file($artifactPath . '/' . $relative)) {
        $patterns[] = '!/' . $relative;
      }
    }

    file_put_contents($artifactPath . '/.gitignore', implode("\n", $patterns) . "\n");
    $this->log('Generated artifact .gitignore');
  }

  /**
   * Returns the base .gitignore pattern list for the artifact.
   *
   * @param string $docroot
   *
   * @return string[]
   */
  protected function getBaseGitIgnorePatterns(string $docroot) : array {
    return [
      '# Ignore sensitive information.',
      "/$docroot/sites/*/settings.local.php",
      '# Ignore local drush settings',
      "/$docroot/sites/*/local.drush.yml",
      '# Ignore paths that contain user-generated content.',
      "/$docroot/sites/*/files",
      '/private-files/*',
      '# OS X files.',
      '.DS_Store',
      '# Linux files.',
      '.directory',
      '# IDE related directories.',
      '/nbproject/private/',
      '.idea',
      '# Database and compressed files.',
      '*.mysql',
      '*.sql',
      '*.gz',
      '*.zip',
      '*.rar',
      '*.7z',
      '# NPM.',
      'node_modules/',
      '.sass-cache',
      '.cache',
      '# Test related Reports.',
      '/reports/behat/errors/*',
      '/reports/behat/junit/*',
      '/reports/codereview/*',
      "/$docroot/sites/default/settings.local.unmanaged.php",
      '# BackstopJS',
      '/tests/backstopjs/backstop_data/html_report',
      '/tests/backstopjs/backstop_data/bitmaps_test',
      '# Temporary files',
      '/tmp/*',
      '# Ignore docker-compose env specific settings.',
      '/docker-compose.override.yml',
      '# Ensure .gitkeep files are commited so folder structure get respected.',
      '!.gitkeep',
      '# Ignore editor config files.',
      '/.editorconfig',
      '/.gitattributes',
      '# Ignore documentation files everywhere except contrib modules and themes.',
      "README.md",
      "**/README.txt",
      "/vendor/**/*.md",
      "/drush/**/*.md",
      "/$docroot/core/**/*.md",
      "/$docroot/libraries/**/*.md",
      "/$docroot/example.gitignore",
      "!/$docroot/modules/**/*.md",
      "!/$docroot/themes/**/*.md",
      ".csslint*",
      "**/.csslint*",
      ".eslint*",
      "**/.eslint*",
      "**/LICENSE.txt",
      "**/CHANGELOG.txt",
      "**/changelog.txt",
      "**/COPYRIGHT.txt",
      "**/INSTALL.*.txt",
      "**/INSTALL.txt",
      "**/UPDATE.txt",
      "**/USAGE.txt"
    ];
  }

  /**
   * Returns submodule paths that intersect artifact-included paths.
   *
   * @return array<string, string[]>
   *   Map of submodule path => list of subpaths (relative to the submodule
   *   root) that must be archived. An empty list means archive the whole
   *   submodule.
   */
  protected function getArtifactSubmodulePaths(): array {
    $includedPaths = $this->getWhitelistPaths();

    $output = trim($this->runCommand('git config --file .gitmodules --get-regexp \'\.path$\'')->getOutput());
    if (empty($output)) {
      return [];
    }

    $submodules = [];
    foreach (explode("\n", $output) as $line) {
      $parts = preg_split('/\s+/', trim($line), 2);
      $submodulePath = trim($parts[1] ?? '');
      if (empty($submodulePath)) {
        continue;
      }

      $subpaths = [];
      $wholeSubmodule = FALSE;
      foreach ($includedPaths as $includedPath) {
        if ($includedPath === $submodulePath || $this->pathIsAtOrUnder($submodulePath, $includedPath)) {
          // The include covers the entire submodule (use case 1).
          $wholeSubmodule = TRUE;
          break;
        }
        if ($this->pathIsAtOrUnder($includedPath, $submodulePath)) {
          // The include is a subpath inside the submodule (use case 2).
          $subpaths[] = substr($includedPath, strlen($submodulePath) + 1);
        }
      }

      if ($wholeSubmodule) {
        $submodules[$submodulePath] = [];
      }
      elseif (!empty($subpaths)) {
        $submodules[$submodulePath] = array_values(array_unique($subpaths));
      }
    }

    $log = [];
    foreach ($submodules as $path => $subpaths) {
      $log[] = empty($subpaths) ? $path : ($path . ' (subpaths: ' . implode(', ', $subpaths) . ')');
    }
    $this->log(sprintf(
      'Submodules included in artifact: %s',
      empty($log) ? '(none)' : implode('; ', $log)
    ));

    return $submodules;
  }

  /**
   * Returns the full whitelist of paths that must end up in the artifact.
   *
   * @return string[]
   *   Normalised paths (no leading slash, no trailing slash).
   */
  protected function getWhitelistPaths(): array {
    $paths = array_merge(
      $this->getRequiredFiles(),
      $this->getSymlinks(),
      $this->getConfiguration()->getInclude()
    );
    $normalised = [];
    foreach ($paths as $path) {
      $path = trim($path, '/');
      if ($path !== '') {
        $normalised[$path] = $path;
      }
    }
    return array_values($normalised);
  }

  /**
   * Whether $candidate is at or under $parent (segment-wise).
   */
  protected function pathIsAtOrUnder(string $candidate, string $parent): bool {
    $candidate = trim($candidate, '/');
    $parent = trim($parent, '/');
    if ($candidate === $parent) {
      return TRUE;
    }
    return str_starts_with($candidate, $parent . '/');
  }

  /**
   * Uses a throwaway git repo to run git clean -fdX, removing ignored files.
   */
  protected function cleanIgnoredFilesFromArtifact() {
    $this->log('Removing ignored files from artifact using git clean');
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $this->runCommandInFolder('git init', $artifactPath);
    $this->runCommand(sprintf('rm -f %s/%s/.gitignore', $artifactPath, $this->calculateDocrootFolder()));
    $this->runCommandInFolder('git clean -fdX', $artifactPath);
    $this->runCommand(sprintf('rm -rf %s/.git', $artifactPath));
    $this->runCommand(sprintf('rm -f %s/.gitignore', $artifactPath));
  }

}
