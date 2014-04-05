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
    if (!isset($this->server['branch'])) $this->server['branch'] = 'master';
    $this->cwd = getcwd();
    $home = dirname(NGN_ENV_PATH);
    $this->paths = [
      "$home",
      "$home/ngn-env/projects",
      "$home/ngn-env",
    ];
  }

  protected function findGitFolders($filter = []) {
    if ($filter) $filter = (array)$filter;
    $folders = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if ($filter and !in_array(basename($folder), $filter)) continue;
        if (!is_dir("$folder/.git")) continue;
        $folders[] = $folder;
      }
    }
    return $folders;
  }

  protected $errorsText = '';

  protected function shellexec($cmd, $output = true) {
    $r = Cli::shell($cmd, $output);
    if (preg_match('/(?<!all)error/i', $r) or preg_match('/fatal/i', $r)) $this->errorsText .= $r;
    return $r;
  }

  protected function wdRev($branch) {
    return trim($this->shellexec("git rev-parse refs/heads/$branch", false));
  }

  protected function repoRev($remote, $branch) {
    return trim($this->shellexec("git rev-parse refs/remotes/$remote/$branch", false));
  }

  /**
   * Возвращает имя ветви текущего рабочего каталога
   *
   * @return string
   */
  protected function wdBranch() {
    return trim($this->shellexec("git rev-parse --abbrev-ref HEAD", false));
  }

  protected function remoteBranches() {
    return array_map('trim', explode("\n", trim($this->shellexec("git branch -r", false))));
  }

}