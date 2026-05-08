<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronize generated artifact changes into git.
 */
class DrupalArtifactBuilderGit extends BaseCommand {

  protected static $defaultName = 'git';

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->setDescription('Commit and push artifact changes to git.');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git commit author');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output): void {
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

    if ($input->hasOption('author') && !empty($input->getOption('author'))) {
      $this->getConfiguration()->setAuthor($input->getOption('author'));
    }
    $this->log(sprintf('Commit author: %s', $this->getConfiguration()->getAuthor()));

    $this->assertArtifactExists();
    $this->assertRepository();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->gitSetup();
    $this->gitCommitPush();
    return Command::SUCCESS;
  }

  /**
   * Clones the artifact repository and places its .git into the artifact folder.
   */
  protected function gitSetup() {
    $this->log('Setting up git');

    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $branch = $this->getConfiguration()->getBranch();
    $tmpGit = sys_get_temp_dir() . '/drupal-artifact-' . uniqid();

    $ls_remote = $this->runCommand(sprintf(
      'git ls-remote --heads %s %s',
      escapeshellarg($this->getConfiguration()->getRepository()),
      escapeshellarg($branch)
    ));
    $branch_exists = !empty(trim($ls_remote->getOutput()));

    $this->runCommand(sprintf(
      'git clone %s %s --depth 1 %s',
      $branch_exists ? sprintf('--branch %s', escapeshellarg($branch)) : '',
      escapeshellarg($this->getConfiguration()->getRepository()),
      escapeshellarg($tmpGit)
    ));

    if (!$branch_exists) {
      $this->runCommandInFolder(
        sprintf('git checkout -b %s', escapeshellarg($branch)),
        $tmpGit
      );
    }

    $this->runCommand(sprintf('cp -r %s/.git %s/.git', $tmpGit, $artifactPath));
    $this->runCommand(sprintf('rm -rf %s', $tmpGit));

    $this->log('Git setup complete');
  }

  /**
   * Commits and pushes all artifact changes.
   */
  protected function gitCommitPush() {
    $artifactPath = $this->rootFolder . '/' . $this->getArtifactFolder();
    $branch = $this->getConfiguration()->getBranch();

    $this->runCommandInFolder('git add .', $artifactPath);

    $diff = $this->runCommandInFolder('git diff --cached --name-only', $artifactPath);
    $diff_output = trim($diff->getOutput());

    if (!empty($diff_output)) {
      $this->log('Commiting and pushing changes to the artifact repository:');
      $this->log($diff_output);
      $this->runCommandInFolder(
        sprintf(
          'git commit -m "Artifact commit by artifact generation script" --author=%s',
          escapeshellarg($this->getConfiguration()->getAuthor())
        ),
        $artifactPath
      );
      $this->runCommandInFolder(
        sprintf('git push origin %s', escapeshellarg($branch)),
        $artifactPath
      );
      $this->log('Changes pushed to the artifact repository');
    }
    else {
      $this->log('No changes to commit!');
    }
  }

  /**
   * Assert that the artifact exists before trying to push it.
   *
   * @throws \Exception
   */
  protected function assertArtifactExists() {
    if (!file_exists($this->getArtifactFolder()) || count(glob(sprintf('%s/%s', $this->getArtifactFolder(), '*'))) == 0) {
      throw new \Exception('Artifact does not exists');
    }
  }

}
