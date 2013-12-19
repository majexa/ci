<?php

/**
 * Класс для управления версиями всех проектов на dev-сервере
 */
class GitFolder extends GitBase {

  protected $folder;

  function __construct($folder) {
    parent::__construct();
    $this->folder = $folder;
    chdir($this->folder);
  }

  function reset() {
    return $this->resetToRemote('origin');
  }
  
  protected function resetToRemote($remote) {
    //output("Fetch '{$this->folder}' folder");
    chdir($this->folder);
    $this->shellexec("git fetch $remote", false);
    $wdCommit = $this->wdRev();
    $repoCommit = $this->repoRev($remote);
    if ($wdCommit != $repoCommit) {
      output("Resetting folder '{$this->folder}' to the HEAD of '$remote' remote");
      $this->shellexec("git reset --hard $remote/{$this->wdBranch()}");
      return true;
    }
    return false;
  }

  protected function isClean() {
    return (bool)strstr($this->shellexec("git status"), '(working directory clean)');
  }

  function start() {
    $branch = $this->wdBranch();
    if ($branch == $this->masterBranch and !$this->isClean()) {
      print "U must cleanup working dir '$this->folder' first. Some changes presents in current local $this->masterBranch branch.\n";
      return;
    }
    if ($branch == $this->server['branch']) {
      print ("'$this->folder' are already on dev phase (branch {$this->server['branch']}).\n");
      return;
    }
    print "Pulling '$this->folder' from $this->masterBranch branch.\n";
    $this->shellexec("git pull origin $this->masterBranch");
    $this->shellexec("git checkout -b {$this->server['branch']}");
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
      output("$folder: no remotes".($remoteFilter ? '. Filter: '.implode(', ', $remoteFilter) : ''));
      return;
    }
    output("$folder: try to add and commit. Remotes: ".implode(', ', $remotes));
    print `git add .`;
    $r = `git commit -am "Commit was made from server {$this->server['maintainer']}/{$this->server['baseDomain']} by ci/push"`;
    if (strstr($r, 'nothing to commit')) {
      output("$folder: nothing to commit");
      return;
    }
    if (!$this->hasChanges($remotes)) {
      output("$folder: no changes");
      return;
    }
    foreach ($remotes as $remote) {
      output("$folder: process remote '$remote'");
      $this->shellexec("git pull $remote {$this->server['branch']}");
      $this->shellexec("git push $remote {$this->server['branch']}");
    }
  }

  protected function hasChanges(array $remotes) {
    foreach ($remotes as $remote) if ($this->wdRev() != $this->repoRev($remote)) return true;
    return false;
  }

  function release() {
    print "Release '$this->folder' to $this->masterBranch branch\n";
    print `git fetch origin -v`;
    print `git checkout $this->masterBranch`;
    //print `git merge {$this->server['branch']}`;
    print `git push origin $this->masterBranch`;
    //print `git branch --delete {$this->server['branch']}`;
  }

  function master() {
    print `git fetch origin -v`;
    print `git checkout $this->masterBranch`;
    print `git reset --hard origin/$this->masterBranch`;
  }

}