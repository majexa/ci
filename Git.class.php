<?php

/**
 * @method start
 * @method push
 * @method update
 * @method release
 */
class Git extends GitBase {

  function __call($method, $args) {
    foreach ($this->findGitFolders() as $folder) {
      call_user_func_array([new GitFolder($folder), $method], $args);
    }
    chdir($this->cwd);
  }

}