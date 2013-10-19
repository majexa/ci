<?php

/**
 * @method start
 * @method push
 * @method update
 * @method release
 */
class GitRefactor extends GitBase {

  function __call($method, $args) {
    foreach ($this->findGitFolders() as $folder) (new GitDevFolder($folder))->$method();
    chdir($this->cwd);
  }

}