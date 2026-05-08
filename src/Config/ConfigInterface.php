<?php

namespace DrupalArtifactBuilder\Config;

interface ConfigInterface
{

  const DEFAULT_COMMIT_AUTHOR = 'Drupal <drupal@artifact-builder>';

  /**
   * Branch where artifact is pushed.
   *
   * Default value: current branch.
   *
   * @var string
   */
  public function getBranch() : string;

  /**
   * Sets the branch where artifact is pushed.
   *
   * @param string $branch
   *   Branch name.
   */
  public function setBranch(string $branch) : void;

  /**
   * Gets the repository where the commits will be pushed.
   *
   * @return string|null
   *   Repository name, if defined.
   */
  public function getRepository() : ?string;

  /**
   * Sets the repository.
   *
   * @param string $repository
   *   Repository HTTP(s) URL or SSH.
   */
  public function setRepository(string $repository) : void;

  /**
   * Extra file/folders that will be added to the artifact.
   *
   * @return array|string[]
   *   Relative path to the file or folder.
   */
  public function getInclude() : array;

  /**
   * Sets extra file / folders that will be included.
   *
   * @param array $include
   *   Include.
   */
  public function setInclude(array $include) : void;

  /**
   * Gets the author used in commits.
   *
   * @return string
   *   Authro.
   */
  public function getAuthor() : string;

  /**
   * Sets the author.
   *
   * @param string $author
   *   Author in git commit format: Name<email>
   */
  public function setAuthor(string $author) : void;

  /**
   * Extra paths to exclude from the artifact.
   *
   * @return array
   */
  public function getExclude() : array;

  /**
   * Sets extra paths to exclude.
   *
   * @param array $exclude
   */
  public function setExclude(array $exclude) : void;

  /**
   * Commands to run inside the artifact folder before packaging.
   *
   * @return array
   */
  public function getCommands() : array;

  /**
   * Sets pre-artifact commands.
   *
   * @param array $commands
   */
  public function setCommands(array $commands) : void;

  /**
   * Gets the artifact destination folder name.
   *
   * @return string|null
   */
  public function getArtifactFolder() : ?string;

  /**
   * Sets the artifact destination folder name.
   *
   * @param string $folder
   */
  public function setArtifactFolder(string $folder) : void;

}
