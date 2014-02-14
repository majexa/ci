<?php

class IssueFolder extends GitFolder {

  function startIssue($name) {
    /*
    $branch = $this->wdBranch();
    if ($branch == $this->masterBranch and !$this->isClean()) {
      print "U must cleanup working dir '$this->folder' first. Some changes presents in current local $this->masterBranch branch.\n";
      return;
    }
    if ($branch == $this->server['branch']) {
      print ("'$this->folder' are already on dev phase (branch {$this->server['branch']}).\n");
      return;
    }
    */
    $this->shellexec("git stash");
    output("Pulling '$this->folder' from $this->masterBranch branch");
    $this->shellexec("git pull origin $this->masterBranch");
    $this->shellexec("git checkout -b $name");
  }

  function completeIssue() {
    print "Release '$this->folder' to $this->masterBranch branch\n";
    print `git fetch origin -v`;
    print `git checkout $this->masterBranch`;
    //print `git merge {$this->server['branch']}`;
    print `git push origin $this->masterBranch`;
    //print `git branch --delete {$this->server['branch']}`;
  }

}