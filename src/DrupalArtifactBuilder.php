<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Generates an artifact from a site already that is already setup.
 *
 * @TODO:
 *   - Create two helper commands, one for building, and other for pushing.
 *   - Use this command only to call the other two commands.
 *   - Add the git author parameter.
 */
class DrupalArtifactBuilder extends Command {

  protected static $defaultName = 'build';

  const ARTIFACT_FOLDER = 'deploy-artifact';

  const ARTIFACT_REPOSITORY_FOLDER = 'deploy-artifact-repository';

  /**
   * Branch where the artifact will be created.
   *
   * Its value will be set with the current branch.
   *
   * @var string
   */
  protected string $branch;

  /**
   * Web document root folder.
   *
   * @var string
   */
  protected string $docrootFolder;

  /**
   * URL / SSH of the repository.
   *
   * @var string
   */
  protected string $repository;

  /**
   * Extra paths which will be used to generate the artifact.
   */
  protected ?string $extraPaths;

  /**
   * Folder with the codebase.
   *
   * @var string
   */
  protected string $rootFolder;

  /**
   * Used to show messages during the artifact building.
   *
   * @var OutputInterface
   */
  protected OutputInterface $output;

  /**
   * If true a symlink will be generated.
   *
   * @var bool
   */
  protected bool $generateSymlink;

  /**
   * Symlink location.
   *
   * @var string
   */
  protected string $symlink;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setDescription('Helper to generate drupal artifacts.');
    $this->addOption('docroot', 'docroot', InputOption::VALUE_REQUIRED,'Name of the docroot folder', 'web');
    $this->addOption('repository', 'repo', InputOption::VALUE_REQUIRED,'Git repository URL / SSH');
    $this->addOption('extra-paths', 'ef', InputOption::VALUE_OPTIONAL, 'Separated by commas list of extra paths that must be copied.');
    $this->addOption('symlink', 'sm', InputOption::VALUE_REQUIRED, 'Symbolic link location of the codebase', 'public_html');
    $this->addOption('no-symlink', 'no-sm', InputOption::VALUE_NONE, 'Add this parameter to not create a symlink');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    // Variables initialization.
    $this->output = $output;
    $this->rootFolder = getcwd();
    $this->docrootFolder = $input->getOption('docroot');
    $this->repository = $input->getOption('repository');
    $this->extraPaths = $input->getOption('extra-paths');
    $this->generateSymlink = !((bool) $input->getOption('no-symlink'));
    $this->symlink = $input->getOption('symlink');

    // Branch setup.
    $this->branch = $this->getCurrentBranch();
    $this->log(sprintf('Selected %s branch', $this->branch));

    // Assert the site is working okay before starting to create the artifact
    $this->assertRootLocation();
    $this->assertRepositoryIsClean();

  }


  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->generateArtifact();
    $this->gitSetup();
    $this->gitCommitPush();

    $this->log(sprintf('Artifact generation finished successfully in the %s folder', self::ARTIFACT_FOLDER));
    $this->log("Take into account that the operation removed development packages so you may want to run 'composer install'");
    $this->log("Please, complete the process with:\n  - Adding a tag (if needed)\n  - Merging with master (if this is a prod release)\n  - git push\n");

    return 0;
  }

  /**
   * Get the name of the codebase current branch.
   *
   * @return string
   *   Branch name.
   */
  protected function getCurrentBranch() {
    $branch = trim($this->runCommand('echo ${GIT_BRANCH:-$(git branch --show-current)}')->getOutput());
    if (empty($branch)) {
      throw new \RuntimeException("Could not detect the selected branch. Either you didn't set GIT_BRANCH environment variable or you are in deatached mode");
    }

    return $branch;
  }

  /**
   * Generates the artifact
   *
   * @throws \Exception
   */
  protected function generateArtifact() {
    // Create the folder with the artifact.
    $this->createArtifactFolder();
    $this->assertRepositoryIsClean();

    $this->log('Cleaning previous artifact');
    $this->log('##########################');

    // Cleanup artifact content to start from scratch.
    try {
      $this->runCommand(sprintf('rm -rf %s/*', static::ARTIFACT_FOLDER));
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Removing of dependencies failed');
    }

    // Copy all needed files.
    $this->log('Starting source copy to artifact repository folder');
    $this->log('##################################################');
    $this->copy('config');
    $this->copy('drush');
    $this->copy('vendor');
    $this->copy('scripts');
    $this->copy($this->docrootFolder);
    $this->copy('patches');

    if (!empty($this->extraPaths)) {
      foreach (explode(',') as $path) {
        $this->copy($path);
      }
    }

    // Generates the symbolic link.
    if ($this->generateSymlink) {
      chdir(self::ARTIFACT_FOLDER);

      $symlink_parts = explode('/', $this->symlink);
      // When the symlink has one or more folders behind,
      // the symlink is calculated so it takes in account every sublevel
      // and the symlink does not appear broken.
      if (count($symlink_parts) > 1) {
        array_pop($symlink_parts);
        $this->runCommand(sprintf('mkdir -p %s', implode('/', $symlink_parts)));
        $directory_levels = count($symlink_parts);
        $directory_levels_string = '';
        for ($i = 0; $i < $directory_levels; $i++) {
          $directory_levels_string  .= '../';
        }
        $this->runCommand(sprintf('ln -s %s%s %s', $directory_levels_string, $this->docrootFolder, $this->symlink));
      }
      else {
        $this->runCommand(sprintf('ln -s %s %s', $this->docrootFolder, $this->symlink));
      }

      $this->log(sprintf('Symlink generated from %s to %s', $this->docrootFolder, $this->symlink));

      chdir($this->rootFolder);
    }

    $this->log('Artifact generated successfully');
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
      $this->runCommand(sprintf('echo %s > %s/%s/hash.txt', $hash, self::ARTIFACT_FOLDER, $this->docrootFolder));
      $this->log('Added hash file');
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Could not generate commit hash to place in the artifact folder');
    }

    $this->generateGitIgnore();

    $this->log('Operation to copy source to artifact repository folder finished successfully');

    chdir(self::ARTIFACT_FOLDER);

    $files_to_clean = [
      'CHANGELOG.txt',
      'COPYRIGHT.txt',
      'INSTALL.txt',
      'INSTALL.mysql.txt',
      'INSTALL.pgsql.txt',
      'INSTALL.sqlite.txt',
      'LICENSE.txt',
      'README.txt',
      'CHANGELOG.txt',
      'UPDATE.txt',
      'USAGE.txt',
      'PATCHES.txt',
    ];

    foreach ($files_to_clean as $file) {
      $this->cleanFileFromArtifact($file);
    }

    // Clean .git folders on contrib modules to avoid Acquia detect them as submodules.
    $this->runCommand(sprintf('find %s/modules/contrib -name ".git" -exec rm -fr {} +', $this->docrootFolder));

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
    $this->runCommand('git commit -m "Artifact commit by artifact generation script"');
    $this->runCommand(sprintf('git push origin', $this->branch));
    $this->log('Changes pushed to the artifact repository');
    chdir($this->rootFolder);
  }

  /**
   * Create the folder that will contains the artifact.
   */
  protected function createArtifactFolder() {
    $this->log('Creating artifact folder');
    $this->runCommand(sprintf('mkdir %s', static::ARTIFACT_FOLDER));
  }

  /**
   * Copy file or folder from codebase to artifact.
   *
   * @param string $path
   *   File or folder path from codebase.
   */
  protected function copy(string $path) {
    $this->log(sprintf('Copying %s...', $path));

    $this->runCommand(sprintf('cp -a "%s" "%s/%s"', $path, self::ARTIFACT_FOLDER, $path));
    $this->log('Copy successful');
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
   * Assert the script is launched inside a codebase and not in an arbitrary folder.
   */
  protected function assertRootLocation() {
    foreach ([$this->docrootFolder, 'config', 'composer.json'] as $path) {
      if (!file_exists($path)) {
        throw new \RuntimeException('It seems this command has not been launched the repository root folder. Please run it from root folder.');
      }
    }
  }

  /**
   * Assert the repository does not contains changes / untracked files.
   *
   * @throws \Exception
   */
  protected function assertRepositoryIsClean() {
    $num_changes = (int) trim($this->runCommand('git status --porcelain | grep -v .env |wc -l')->getOutput());
    if ($num_changes > 0) {
      throw new \Exception('There are changes in the repository (changed and/or untracked files), please run this artifact generation script with folder tree clean.');
    }
  }

  /**
   * Generate git ignore file.
   */
  protected function generateGitIgnore() {
    $docroot_folder = $this->docrootFolder;
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
   * Runs a shell command.
   *
   * @param string $command
   *   Command.
   *
   * @return Process
   *   It can be used to obtain the command output if needed.
   *
   * @throws ProcessFailedException
   *   When the command fails.
   */
  protected function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  /**
   * Logs that will show the user the artifact building progress.
   *
   * @param string $message
   *   Message.
   */
  protected function log(string $message) {
    $this->output->writeln(sprintf('[-->] %s', $message));
  }

}
