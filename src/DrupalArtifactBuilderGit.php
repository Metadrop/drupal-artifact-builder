<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronize generated artifact changes into git.
 */
class DrupalArtifactBuilderGit extends BaseCommand {

  protected static $defaultName = 'git';

  const GIT_IGNORED_REQUIRED_WEB_FILES = [
    'index.php',
    'robots.txt',
    'autoload.php',
    'update.php',
    'web.config',
    '.htaccess',
    '.ht.router.php',
  ];

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Commit and push artifact changes to git.');
    $this->addOption('branch', 'b', InputOption::VALUE_REQUIRED,'Git branch');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED,'Git commit author');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);

    if (!$this->gitCommandExist()) {
      throw new \RuntimeException("Git command not found. Git must be installed and available in the PATH variable to generate an artifact.");
    }

    // Branch setup.
    if ($input->hasOption('repository') && !empty($input->getOption('repository'))) {
      $this->config->setRepository($input->getOption('repository'));
    }
    $selected_branch = $this->getBranch($input);
    $this->getConfiguration()->setBranch($selected_branch);
    $this->log(sprintf('Source branch: %s', $selected_branch));
    $this->log(sprintf('Target branch: %s', $this->getConfiguration()->getBranch()));
    $this->assertArtifactExists();

    if ($input->hasOption('author') && !empty($input->getOption('author'))) {
      $this->getConfiguration()->setAuthor($input->getOption('author'));
    }
    $this->log(sprintf('Commit author: %s', $this->getConfiguration()->getAuthor()));

    $this->assertRepository();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) : int {
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

    $branch = $this->getConfiguration()->getBranch();
    $ls_remote = $this->runCommand(sprintf('git ls-remote --heads %s %s', $this->getConfiguration()->getRepository(), $branch));
    $ls_remote_output = trim($ls_remote->getOutput());
    $branch_exists = !empty($ls_remote_output);

    $this->runCommand(sprintf(
      'git clone  %s %s --depth 1 %s',
        $this->getConfiguration()->getRepository(),
      $branch_exists ? sprintf('--branch %s', $branch) : '',
      self::ARTIFACT_REPOSITORY_FOLDER)
    );

    chdir(self::ARTIFACT_REPOSITORY_FOLDER);

    // Checkout to new branch only when branch does not exists.
    if (!$branch_exists) {
      $this->runCommand(sprintf('git checkout -b %s', $this->getConfiguration()->getBranch()));
    }

    chdir($this->rootFolder);

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
    $this->gitAddFiles();
    // Check if there are changes to commit.
    $diff = $this->runCommand('git diff --cached --name-only');
    $diff_output = trim($diff->getOutput());
    if (!empty($diff_output)) {
      $this->log('Commiting and pushing changes to the artifact repository:');
      $this->log($diff_output);
      $this->runCommand(sprintf('git commit -m "Artifact commit by artifact generation script" --author="%s"', $this->getConfiguration()->getAuthor()));
      $this->runCommand(sprintf('git push origin %s', $this->getConfiguration()->getBranch()));
      $this->log('Changes pushed to the artifact repository');
    }
    else {
      $this->log('No changes to commit!');
    }
    chdir($this->rootFolder);
  }

  /**
   * Add all the files to the git repository.
   */
  protected function gitAddFiles() {
    $this->runCommand('git add .');

    $ignored_web_files = array_map(function (string $file) {
      return sprintf('%s/%s', $this->calculateDocrootFolder(), $file);
    }, self::GIT_IGNORED_REQUIRED_WEB_FILES);

    foreach (array_unique(array_merge($ignored_web_files, $this->getConfiguration()->getInclude())) as $file) {
      if (file_exists($file) && !is_link($file)) {
        $this->runCommand(sprintf('git add -f %s', $file));
      }
    }
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

  /**
   * Get the name of the repository branch.
   *
   * @param \Symfony\Component\Console\Input\InputInterface
   *   Shell input, used to get the branch from options.
   *
   * @return string
   *   Branch name.
   */
  protected function getBranch(InputInterface $input) {
    $branch_from_input = $input->getOption('branch');
    if (!empty($branch_from_input)) {
      return $branch_from_input;
    }

    $current_branch = trim($this->runCommand('git branch --show-current')->getOutput());
    if (!empty($current_branch)) {
      return $current_branch;
    }

    throw new \RuntimeException("Could not detect a branch. Either you didn't set --branch option or you are in detached mode");
  }

}
