<?php

namespace DrupalArtifactBuilder;

use PHP_CodeSniffer\Tests\Core\File\testFECNClassThatImplementsAndExtends;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Synchronize generated artifact changes into git.
 */
class DrupalArtifactBuilderGit extends BaseCommand {

  protected static $defaultName = 'git';

  protected string $author;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Commit and push artifact changes to git.');
    $this->addOption('repository', 'repo', InputOption::VALUE_REQUIRED,'Git repository URL / SSH');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED,'Git commit author', 'Drupal <drupal@artifact-builder>');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);

    // Branch setup.
    $this->repository = $input->getOption('repository');
    $this->branch = $this->getCurrentBranch();
    $this->log(sprintf('Selected %s branch', $this->branch));

    $this->assertArtifactExists();

    $this->author = $input->getOption('author');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->gitSetup();
    $this->gitCommitPush();

    return 0;
  }

  /**
   * Setup the artifact repository so the git changed can be commited and pushed later.
   */
  protected function gitSetup() {
    $this->log('Setting up git');

    // Clone the artifact repository and copy the .git into the artifact folder.
    // This is done after creating the artifact and not before
    // so there are no residual files, plus giving more options
    // to create artifacts than pushing the changes to a git repository (s.e.: generating a .tar.gz.).
    $this->runCommand(sprintf('git clone %s %s -b %s', $this->repository, self::ARTIFACT_REPOSITORY_FOLDER, $this->branch));
    $this->runCommand(sprintf('cp -r %s/.git %s', self::ARTIFACT_REPOSITORY_FOLDER, SELF::ARTIFACT_FOLDER));
    $this->runCommand(sprintf('rm -rf %s', self::ARTIFACT_REPOSITORY_FOLDER));

    try {
      // Add hash.txt file with current source repository hash to know what hash is
      // deployed in an environment just checking an url.
      $hash = trim($this->runCommand('git rev-parse HEAD')->getOutput());
      $this->runCommand(sprintf('echo %s > %s/%s/hash.txt', $hash, self::ARTIFACT_FOLDER, $this->calculateDocrootFolder()));
      $this->log('Added hash file');
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Could not generate commit hash to place in the artifact folder');
    }

    $this->generateGitIgnore();

    $this->log('Operation to copy source to artifact repository folder finished successfully');

    chdir(self::ARTIFACT_FOLDER);

    foreach (self::FILES_TO_CLEAN as $file) {
      $this->cleanFileFromArtifact($file);
    }

    // Clean .git folders on contrib modules to avoid git detect them as submodules.
    $this->runCommand(sprintf('find %s/modules/contrib -name ".git" -exec rm -fr {} +', $this->calculateDocrootFolder()));
    $this->runCommand(sprintf('find %s/themes/contrib -name ".git" -exec rm -fr {} +', $this->calculateDocrootFolder()));
    $this->runCommand('find vendor -name ".git" -exec rm -fr {} +');

    chdir($this->rootFolder);

    $this->log('The txt files and .git files have been cleaned up successfully');
  }

  /**
   * Commit and push all the changes.
   */
  protected function gitCommitPush() {
    chdir(self::ARTIFACT_FOLDER);
    $this->log('Commiting and pushing changes to the artifact repository...');
    $this->runCommand('git add .');
    $this->runCommand(sprintf('git commit -m "Artifact commit by artifact generation script" --author="%s"', $this->author));
    $this->runCommand(sprintf('git push origin', $this->branch));
    $this->log('Changes pushed to the artifact repository');
    chdir($this->rootFolder);
  }

  /**
   * Clean a specific file from the artifact.
   *
   * Use this when you copy a folder that contains unneeded files.
   *
   * @param string $filename
   *   File name.
   */
  protected function cleanFileFromArtifact(string $filename) {
    $this->runCommand(sprintf('find . -name "%s" -exec rm {} \;', $filename));
    $this->log(sprintf('File(s) %s removed or not present in artifact', $filename));
  }

  /**
   * Generate git ignore file.
   */
  protected function generateGitIgnore() {
    $docroot_folder = $this->calculateDocrootFolder();
    $git_ignore = "# Ignore sensitive information.
/$docroot_folder/sites/*/settings.local.php
# Ignore local drush settings
/$docroot_folder/sites/*/local.drush.yml
# Ignore paths that contain user-generated content.
/$docroot_folder/sites/*/files
/private-files/*
# OS X files.
.DS_STORE
.Ds_Store
.DS_Store
# Linux files.
.directory
# IDE related directories.
/nbproject/private/
.idea
# Database and compressed files.
*.mysql
*.sql
*.gz
*.zip
*.rar
*.7z
# NPM.
node_modules/
.sass-cache
.cache
# Test related Reports.
/reports/behat/errors/*
/reports/behat/junit/*
/reports/codereview/*
/$docroot_folder/sites/default/settings.local.unmanaged.php
# BackstopJS
/tests/backstopjs/backstop_data/html_report
/tests/backstopjs/backstop_data/bitmaps_test
# Temporary files
/tmp/*
# Ignore docker-compose env specific settings.
/docker-compose.override.yml
# Ensure .gitkeep files are commited so folder structure get respected.
!.gitkeep
# Ignore editor config files.
/.editorconfig
/.gitattributes'";

    file_put_contents(sprintf('%s/.gitignore', self::ARTIFACT_FOLDER), $git_ignore);
  }

  /**
   * Assert that the artifact exists before generating it.
   *
   * @throws \Exception
   */
  protected function assertArtifactExists() {
    if (!file_exists(self::ARTIFACT_FOLDER) || count(glob(sprintf('%s/%s', self::ARTIFACT_FOLDER, '*'))) == 0) {
      throw new \Exception('Artifact does not exists');
    }
  }

}
