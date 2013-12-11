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

  protected $errorsText = '';

  protected function shellexec($cmd, $output = true) {
    $r = Cli::shell($cmd, $output);
    // if (preg_match('/(?<!all)error/i', $r) or preg_match('/fatal/i', $r)) throw new Exception("Problems while running cmd '$cmd':\n$r");
    if (preg_match('/(?<!all)error/i', $r) or preg_match('/fatal/i', $r)) $this->errorsText .= $r;
    return $r;
  }

  protected function wdRev() {
    return trim($this->shellexec("git rev-parse HEAD", false));
  }

  protected function repoRev($remote) {
    return trim($this->shellexec("git rev-parse refs/remotes/$remote/master", false));
  }

  /**
   * Возвращает имя ветви текущего рабочего каталога
   *
   * @return string
   * @throws Exception
   */
  protected function wdBranch_old() {
    foreach (explode("\n", `git branch`) as $branch) {
      if (strstr($branch, '* ')) return str_replace('* ', '', $branch);
    }
    throw new Exception("Something wrong");
  }

  /**
   * Возвращает имя ветви текущего рабочего каталога
   *
   * @return string
   */
  protected function wdBranch() {
    return trim($this->shellexec("git rev-parse --abbrev-ref HEAD", false));
  }

  function getRemotes() {
    $r = [];
    foreach (parse_ini_file($this->folder.'/.git/config', true, INI_SCANNER_RAW) as $k => $v) {
      if (Misc::hasPrefix('remote ', $k)) $r[] = trim(Misc::removePrefix('remote ', $k), '"');
    }
    return $r;
  }

}