<?php

/**
 * @method start
 * @method push
 * @method update
 * @method release
 */
class Git extends GitBase {

  function __call($method, $args) {
    foreach ($this->findGitFolders() as $folder) (new GitFolder($folder))->$method();
    chdir($this->cwd);
  }

}