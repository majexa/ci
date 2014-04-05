<?php

/**
 * Популярные команды для работы с git-папками
 */
class GitFolder extends GitBase {

  protected $folder;

  /**
   * @param string Путь к git папке
   */
  function __construct($folder) {
    parent::__construct();
    $this->folder = realpath($folder);
    chdir($this->folder);
  }

  function reset() {
    return $this->resetToRemote('origin');
  }
  
  protected function resetToRemote($remote) {
    chdir($this->folder);
    $this->shellexec("git fetch $remote", false);
    $branch = $this->wdBranch();
    if ($this->wdRev($branch) != $this->repoRev($remote, $branch)) {
      output("Resetting folder '{$this->folder}' to the HEAD of '$remote' remote");
      $this->shellexec("git reset --hard $remote/{$this->wdBranch()}");
      print $this->shellexec('git clean -f -d');
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
   * Для текущей ветки делает add, commit, а так же pull, push для всех репозиториев
   */
  function push($remoteFilter = []) {
    if ($remoteFilter) $remoteFilter = (array)$remoteFilter;
    $folder = basename($this->folder);
    $remotes = $this->getRemotes();
    if ($remoteFilter) $remotes = array_intersect($remotes, $remoteFilter);
    if (!$remotes) {
      output("$folder: skepped. no remotes".($remoteFilter ? '. Filter: '.implode(', ', $remoteFilter) : ''));
      return;
    }
    $hasLocalChanges = false;
    if (!$this->isClean()) {
      output("$folder: try to add and commit. Remotes: ".implode(', ', $remotes));
      print `git add .`;
      print `git commit -am "Commit was made from server {$this->server['baseDomain']} by ci/push"`;
      $hasLocalChanges = true;
    }
    //$hasLocalChanges = strstr($r, 'nothing to commit');
    $branch = $this->wdBranch();
    if (!$hasLocalChanges and !$this->_hasChanges($remotes, $branch)) {
      output("$folder ($branch): no changes remote and local changes");
      return;
    }
    foreach ($remotes as $remote) {
      output("$folder: process remote '$remote'");
      $this->shellexec("git pull $remote $branch");
      $this->shellexec("git push $remote $branch");
    }
  }

  function hasChanges($branch = null) {
    if (!$branch) $branch = $this->wdBranch();
    return $this->_hasChanges($this->getRemotes(), $branch);
  }

  function branchRemotes($branch) {

  }

  protected function _hasChanges(array $remotes, $branch) {
    $remoteBranches = $this->remoteBranches();
    foreach ($remotes as $remote) {
      if (!in_array("$remote/$branch", $remoteBranches)) {
        //output("'{$this->folder}' branch '$remote/$branch' does not exists. Skipped");
        continue;
      }
      //die2([$remote, $this->wdRev($branch), $this->repoRev($remote, $branch)]);
      //output("\nwd rev: ".$this->wdRev($branch)."\nrepo rev: ".$this->repoRev($remote, $branch)."\nremote: $remote\nbranch: $branch");
      if ($this->wdRev($branch) != $this->repoRev($remote, $branch)) return true;
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

  /*
  function master() {
    print `git fetch origin -v`;
    print `git checkout $this->masterBranch`;
    print `git reset --hard origin/$this->masterBranch`;
  }
  */

}