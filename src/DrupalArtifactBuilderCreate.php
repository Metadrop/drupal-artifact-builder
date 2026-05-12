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
    $branch = $this->getBranch($input);
    $this->getConfiguration()->setBranch($branch);
    $this->log(sprintf('Target artifact branch: %s', $this->getConfiguration()->getBranch()));
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->generateArtifact();
    return Command::SUCCESS;
  }

  /**
   * Generates the artifact.
   *
   * @throws \Exception
   */
  protected function generateArtifact() {
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
   * Copies project .git into the artifact folder and checks out the target branch.
   *
   * Git checkout populates all files — no manual file copying needed.
   */
  protected function checkoutBranchInArtifact() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $branch = $this->getConfiguration()->getBranch();

    $this->log(sprintf('Checking out branch "%s" into artifact folder', $branch));

    $this->runCommand(sprintf('cp -r %s/.git %s/.git', $this->rootFolder, $artifactPath));
    try {
      $this->runCommandInFolder('git fetch origin', $artifactPath);
    }
    catch (\Exception $e) {
      $this->output->writeln(sprintf('<warning>[!] git fetch failed, artifact may not reflect the latest remote state: %s</warning>', $e->getMessage()));
    }
    try {
      $this->runCommandInFolder(sprintf('git checkout -fB %s origin/%s', escapeshellarg($branch), escapeshellarg($branch)), $artifactPath);
    }
    catch (\Exception $e) {
      $this->log(sprintf('Branch "origin/%s" not found in remote, checking out locally', $branch));
      $this->runCommandInFolder(sprintf('git checkout -fB %s', escapeshellarg($branch)), $artifactPath);
    }

    if (file_exists($artifactPath . '/.gitmodules')) {
      $this->log('Initializing git submodules');
      $this->runCommandInFolder('git submodule update --init', $artifactPath);
    }
  }

  /**
   * Writes hash.txt to docroot using the original repo commit hash.
   *
   * Must run before .git is removed from the artifact folder.
   */
  protected function generateHashFile() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $hash = trim($this->runCommand('git rev-parse HEAD')->getOutput());
    file_put_contents($artifactPath . '/' . $this->calculateDocrootFolder() . '/hash.txt', $hash . PHP_EOL);
    $this->log(sprintf('Generated hash.txt: %s', $hash));
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

    $whitelist = array_merge(
      $this->getRequiredFiles(),
      $this->getSymlinks(),
      array_map(fn($p) => ltrim($p, '/'), $this->getConfiguration()->getInclude()),
      ['.gitignore']
    );
    $artifactRootEntries = array_filter(
      scandir($artifactPath),
      fn($entry) => !in_array($entry, ['.', '..', '.git'])
    );
    foreach (array_diff($artifactRootEntries, $whitelist) as $extra) {
      $patterns[] = '/' . $extra;
    }

    foreach ($this->getConfiguration()->getExclude() as $excludePath) {
      $patterns[] = $excludePath;
    }

    foreach ($this->getConfiguration()->getInclude() as $includePath) {
      $normalizedPath = '/' . ltrim($includePath, '/');
      if (is_file($artifactPath . '/' . ltrim($includePath, '/'))) {
        $patterns[] = '!' . $normalizedPath;
      }
      else {
        $patterns = array_values(array_filter($patterns, function ($pattern) use ($normalizedPath) {
          return $pattern !== $normalizedPath && $pattern !== rtrim($normalizedPath, '/') . '/';
        }));
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
