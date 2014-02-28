<?php

/**
 * Класс для управления версиями всех проектов на dev-сервере
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
      return true;
    }
    return false;
  }

  protected function isClean() {
    return (bool)strstr($this->shellexec("git status"), 'working directory clean');
  }

  function checkIsClean($message = 'Folder %s is not clear') {
    if (!$this->isClean()) {
      throw new Exception(sprintf($message, $this->folder));
    }
  }

  function update() {
    $this->shellexec("git pull origin $this->masterBranch");
    $this->shellexec("git pull origin {$this->server['branch']}");
  }

  function push($remoteFilter = []) {
    if ($remoteFilter) $remoteFilter = (array)$remoteFilter;
    $folder = basename($this->folder);
    $remotes = $this->getRemotes();
    if ($remoteFilter) $remotes = array_intersect($remotes, $remoteFilter);
    if (!$remotes) {
      output("$folder: skepped. no remotes".($remoteFilter ? '. Filter: '.implode(', ', $remoteFilter) : ''));
      return;
    }
    output("$folder: try to add and commit. Remotes: ".implode(', ', $remotes));
    print `git add .`;
    $r = `git commit -am "Commit was made from server {$this->server['baseDomain']} by ci/push"`;
    $hasLocalChanges = strstr($r, 'nothing to commit');
    $branch = $this->wdBranch();
    if (!$hasLocalChanges and !$this->hasChanges($remotes, $branch)) {
      output("$folder ($branch): no changes remote and local changes");
      return;
    }
    foreach ($remotes as $remote) {
      output("$folder: process remote '$remote'");
      $this->shellexec("git pull $remote $branch");
      $this->shellexec("git push $remote $branch");
    }
  }

  protected function hasChanges(array $remotes, $branch) {
    foreach ($remotes as $remote) if ($this->wdRev($branch) != $this->repoRev($remote, $branch)) return true;
    return false;
  }

  function master() {
    print `git fetch origin -v`;
    print `git checkout $this->masterBranch`;
    print `git reset --hard origin/$this->masterBranch`;
  }

}