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
 */
class BaseCommand extends Command {

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
    $this->addOption('docroot', 'docroot', InputOption::VALUE_REQUIRED,'Name of the docroot folder', 'web');
    $this->addOption('extra-paths', 'ef', InputOption::VALUE_OPTIONAL, 'Separated by commas list of extra paths that must be copied.');
    $this->addOption('symlink', 'sm', InputOption::VALUE_REQUIRED, 'Symbolic link location of the codebase', 'public_html');
    $this->addOption('no-symlink', 'no-sm', InputOption::VALUE_NONE, 'Add this parameter to not create a symlink');
    $this->addOption('no-push', 'nop', InputOption::VALUE_NONE, "If set, the changes won't be commited to git. Use only if you plan to push in other way.");
  }

  protected function report() {
    $this->log(sprintf('Artifact generation finished successfully in the %s folder', self::ARTIFACT_FOLDER));
    $this->log("Take into account that the operation removed development packages so you may want to run 'composer install'");
    $this->log("Please, complete the process with:\n  - Adding a tag (if needed)\n  - Merging with master (if this is a prod release)\n  - git push\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    // Variables initialization.
    $this->output = $output;
    $this->rootFolder = getcwd();
    $this->docrootFolder = $input->getOption('docroot');
    $this->extraPaths = $input->getOption('extra-paths');
    $this->generateSymlink = !((bool) $input->getOption('no-symlink'));
    $this->symlink = $input->getOption('symlink');

    // Assert the site is working okay before starting to create the artifact
    $this->assertRootLocation();
    $this->assertRepositoryIsClean();
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

}
