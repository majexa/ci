<?php

/**
 * @method push
 * @method update
 */
class Git extends GitBase {

  function __call($method, $args) {
    foreach ($this->findGitFolders() as $folder) {
      call_user_func_array([$this->folder($folder), $method], $args);
    }
    chdir($this->cwd);
  }

  protected function folder($folder) {
    return new GitFolder($folder);
  }

}