<?php

class GitBase {

  protected $server, $cwd, $masterBranch = 'master', $paths = [];

  function __construct() {
    if (file_exists(NGN_ENV_PATH.'/config/server.php')) {
      $this->server = require NGN_ENV_PATH.'/config/server.php';
      Arr::checkEmpty($this->server, ['sType', 'baseDomain']);
    }
    else {
      $this->server = [
        'sType'      => 'dev',
        'branch'     => 'master',
        'baseDomain' => `hostname`,
      ];
    }
    $this->cwd = getcwd();
    $home = dirname(NGN_ENV_PATH);
    $this->paths = [
      "$home",
      "$home/ngn-env/projects",
      "$home/ngn-env",
    ];
  }

  protected function findGitFolders() {
    $folders = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (!is_dir("$folder/.git")) continue;
        if (basename($folder) == 'run') continue;
        $folders[] = $folder;
      }
    }
    return $folders;
  }

}