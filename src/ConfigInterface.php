<?php

namespace DrupalArtifactBuilder;

interface ConfigInterface
{
  /**
   * Branch where the artifact will be created.
   *
   * Its value will be set with the current branch.
   *
   * @var string
   */
  public function getBranch() : string;

  /**
   * Gets the repository where the commits will be pushed.
   *
   * @return string
   */
  public function getRepository() : string;

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

}
