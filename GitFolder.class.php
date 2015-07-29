<?php

/**
 * Популярные команды для работы с git-папками
 */
class GitFolder extends GitBase {

  protected $folder;

  /**
   * @param string $folder Путь к git папке
   */
  function __construct($folder) {
    parent::__construct();
    $this->folder = realpath($folder);
    chdir($this->folder);
  }

  function reset() {
    return $this->resetToRemote('origin');
  }
  
  function resetToRemote($remote, $forceRevCheck = false) {
    chdir($this->folder);
    $this->shellexec("git fetch $remote", false);
    $branch = $this->wdBranch();
    if ($forceRevCheck or $this->wdRev($branch) != $this->remoteRev($remote, $branch)) {
      output("Resetting folder '{$this->folder}' to the HEAD of '$remote' remote");
      $this->shellexec("git reset --hard $remote/{$this->wdBranch()}", false);
      $this->shellexec('git clean -f -d', false);
      return true;
    }
    return false;
  }

  function isClean() {
    return (bool)strstr($this->shellexec("git status", false), 'working directory clean');
  }

  function checkIsClean($message = 'Folder %s is not clear') {
    if (!$this->isClean()) {
      print $this->shellexec("git status");
      throw new Exception(sprintf($message, $this->folder));
    }
  }

  function update() {
    $this->shellexec("git pull origin $this->masterBranch");
    $this->shellexec("git pull origin {$this->server['branch']}");
  }

  /**
   * Комитит папку и возвращает TRUE/FALSE в случае успеха/неуспеха
   *
   * @return bool
   */
  function commit() {
    print `git add --all .`;
    $date = date('d.m.Y H:i:s');
    system("git commit -am \"Auto-commit on $date\"", $exitCode);
    return !$exitCode;
  }

  /**
   * Для текущей ветки делает add, commit, а так же pull, push для всех репозиториев
   *
   * @param array $remoteFilter
   * @return bool
   */
  function push($remoteFilter = []) {
    if ($remoteFilter) $remoteFilter = (array)$remoteFilter;
    $folder = basename($this->folder);
    $remotes = $this->getRemotes();
    if ($remoteFilter) $remotes = array_intersect($remotes, $remoteFilter);
    if (!$remotes) {
      output("$folder: skepped. no remotes".($remoteFilter ? '. Filter: '.implode(', ', $remoteFilter) : ''));
      return true;
    }
    $hasLocalChanges = false;
    if (!$this->isClean()) {
      output("$folder: try to add and commit.");
      $this->commit();
      $hasLocalChanges = true;
    }
    $branch = $this->wdBranch();
    if (!$hasLocalChanges and !$this->_hasChanges($remotes, $branch)) {
      output("$folder ($branch): no changes remote and local changes");
      return true;
    }
    foreach ($remotes as $remote) {
      output("{$this->folder}: process remote '$remote'");
      $this->shellexec("git pull $remote $branch");
      $this->shellexec("git push $remote $branch");
    }
    return true;
  }

  function hasChanges($branch = null) {
    if (!$branch) $branch = $this->wdBranch();
    return $this->_hasChanges($this->getRemotes(), $branch);
  }

  protected function _hasChanges(array $remotes, $branch) {
    $remoteBranches = $this->remoteBranches();
    foreach ($remotes as $remote) {
      if (!in_array("$remote/$branch", $remoteBranches)) {
        continue;
      }
      if ($this->wdRev($branch) != $this->remoteRev($remote, $branch)) return true;
    }
    return false;
  }

  function getRemotes($branch = null) {
    $r = [];
    if ($branch) $remoteBranches = $this->remoteBranches();
    foreach (parse_ini_file($this->folder.'/.git/config', true, INI_SCANNER_RAW) as $k => $v) {
      if (Misc::hasPrefix('remote ', $k)) {
        $remote = trim(Misc::removePrefix('remote ', $k), '"');
        if ($branch and !in_array("$remote/$branch", $remoteBranches)) continue;
        $r[] = $remote;
      }
    }
    return $r;
  }

  function localBranches() {
    $r = [];
    foreach (explode("\n", trim(`git branch`)) as $v) {
      $r[] = trim(Misc::removePrefix('* ', $v));
    }
    return $r;
  }

  protected function throwError($cmd, $error) {
    throw new Exception("\nfolder: {$this->folder}\ncmd: $cmd\n".trim($error));
  }

}