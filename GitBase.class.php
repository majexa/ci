<?php

class GitBase {

  protected $server, $cwd, $masterBranch = 'master', $paths = [];

  function __construct() {
    if (!file_exists(NGN_ENV_PATH.'/config/server.php')) {
      print "Lets configure the server\n";
      $server = [];
      if (!($server['baseDomain'] = Cli::prompt("Input server public host name"))) throw new Exception('required');
      if (!($server['maintaner'] = Cli::prompt("Input maintaner email"))) throw new Exception('required');
      if (!($server['git'] = Cli::prompt("Input git URL"))) throw new Exception('required');
      FileVar::updateVar(NGN_ENV_PATH.'/config/server.php', $server);
    }
    $this->server = require NGN_ENV_PATH.'/config/server.php';
    if (!isset($this->server['sType'])) $this->server['sType'] = 'dev';
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

  protected function shellexec($cmd, $output = true) {
    $r = Cli::shell($cmd.' 2>&1', $output); // перенаправляем stderr в stdout
    if (strstr($r, 'fatal:')) $this->throwError($cmd, $r);
    return $r;
  }

  protected function throwError($cmd, $error) {
    throw new Exception("\n$cmd\n".trim($error));
  }

  protected function wdRev($branch) {
    return trim($this->shellexec("git rev-parse refs/heads/$branch", false));
  }

  protected function remoteRev($remote, $branch) {
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