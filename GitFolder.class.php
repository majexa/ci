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
    output("------ Processing '{$this->folder}'");
  }

  protected function currentBranch() {
    foreach (explode("\n", `git branch`) as $branch) {
      if (strstr($branch, '* ')) return str_replace('* ', '', $branch);
    }
    throw new Exception("Something wrong");
  }

  function reset() {
    print "Try reset '$this->folder'\n";
    print `git fetch origin`;
    if ($this->isClean()) return;
    print "Reset '$this->folder'\n";
    print `git ls-files --other --exclude-standard`;
    print `git ls-files --other --exclude-standard | xargs rm`;
    print `git reset --hard origin/{$this->server['branch']}`;
  }

  protected function isClean() {
    return (bool)strstr(`git status`, '(working directory clean)');
  }

  function start() {
    $branch = $this->currentBranch();
    if ($branch == $this->masterBranch and !$this->isClean()) {
      print "U must cleanup working dir '$this->folder' first. Some changes presents in current local $this->masterBranch branch.\n";
      return;
    }
    if ($branch == $this->server['branch']) {
      print ("'$this->folder' are already on dev phase (branch {$this->server['branch']}).\n");
      return;
    }
    print "Pulling '$this->folder' from $this->masterBranch branch.\n";
    print `git pull origin $this->masterBranch`;
    print `git checkout -b {$this->server['branch']}`;
  }

  function update() {
    print `git pull origin $this->masterBranch`;
    print `git pull origin {$this->server['branch']}`;
  }

  function push() {
    output("Pushing {$this->folder}. Wait 10 sec...");
    sleep(10);
    output("Started");
    print `git add .`;
    print `git commit -am "Auto push from {$this->server['baseDomain']}"`;
    foreach ($this->getRemotes() as $remote) {
      Cli::shell("git pull $remote {$this->server['branch']}");
      Cli::shell("git push $remote {$this->server['branch']}");
    }
  }

  function getRemotes() {
    $r = [];
    foreach (parse_ini_file($this->folder.'/.git/config', true, INI_SCANNER_RAW) as $k => $v) {
      if (Misc::hasPrefix('remote ', $k)) $r[] = trim(Misc::removePrefix('remote ', $k), '"');
    }
    return $r;
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