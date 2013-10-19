<?php

/**
 * Continuous integration system
 */
class Ci extends GitBase {

  protected $forceParam;

  function __construct($forceParam = null) {
    parent::__construct();
    $this->forceParam = $forceParam;
  }

  protected function updateFolder($folder) {
    chdir($folder);
    $this->shellexec("git fetch origin");
    $wdCommit = $this->shellexec("git rev-parse HEAD");
    $repoCommit = $this->shellexec("git rev-parse origin");
    if ($wdCommit != $repoCommit) {
      $this->shellexec("git reset --hard origin");
      return true;
    }
    return false;
  }

  protected $updatedFolders = [];

  protected function update() {
    foreach ($this->findGitFolders() as $folder) {
      if ($this->updateFolder($folder)) {
        output("$folder: updated");
        $this->updatedFolders[] = $folder;
      }
      else {
        output("$folder: no changes");
      }
    }
    if ($this->updatedFolders) {
      $this->commonMailText = "Deploy by Continuous integration system at ".date('d.m.Y H:i:s')."\nUpdated folders:\n".implode("\n", $this->updatedFolders)."\n===================\n\n";
    }
  }

  protected $errorsText = '';

  protected function shellexec($cmd) {
    $r = Cli::shell($cmd);
    if (preg_match('/error/i', $r) or preg_match('/error/i', $r)) $this->errorsText .= $r;
    return $r;
  }

  protected function runTest($cmd) {
    $testResult = $this->shellexec($cmd);
    if (strstr($testResult, 'FAILURES!') or strstr($testResult, 'Fatal error')) $this->errorsText .= $testResult;
  }

  protected $commonMailText = '';

  protected function _runTests() {
    $domain = 'test.'.$this->server['baseDomain'];
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec("php pm.php localServer createProject test $domain common");
    //$this->shellexec('php pm.php localProject delete test');
    //$this->shellexec("php pm.php localServer createProject test $domain sb");
    chdir(dirname(__DIR__).'/run');
    $this->runTest('php site.php test "(new ProjectTestRunner)->projectGlobal()"');
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
    chdir(dirname(__DIR__).'/run');
    foreach (glob('/home/user/ngn-env/projects/*', GLOB_ONLYDIR) as $f) {
      if (!is_dir("$f/.git")) continue;
      $project = basename($f);
      $this->runTest('php site.php '.$project.' "(new ProjectTestRunner)->projectLocal()"'); // project level specific tests. on project $project
    }
    chdir(dirname(__DIR__).'/run');
    $this->runTest('php run.php "(new TestRunner)->global()"');
  }

  protected function runTests() {
    if (($this->server['sType'] != 'prod' and $this->updatedFolders and $this->forceParam != 'update') or $this->forceParam == 'test') {
      if ($this->server['sType'] == 'prod') throw new Exception("U can't run tests on production server");
      $this->_runTests();
    }
    else {
      output("no changes");
    }
  }

  protected function sendResults() {
    if ($this->errorsText) {
      (new SendEmail)->send('masted311@gmail.com', "Errors on {$this->server['baseDomain']}", $this->commonMailText.$this->errorsText, false);
      print $this->errorsText;
    }
    else {
      if ($this->commonMailText) {
        $this->commonMailText .= "complete successful";
        (new SendEmail)->send('masted311@gmail.com', "Deploy results on {$this->server['baseDomain']}", $this->commonMailText, false);
      }
      output("complete successful");
    }
  }

  protected function restart() {
    if ($this->updatedFolders or $this->forceParam == 'update') {
      $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects restart');
    }
  }

  protected function clear() {
    chdir(dirname(__DIR__).'/run');
    $this->shellexec('php run.php "(new AllErrors)->clear()"');
    $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects cc');
    print $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects patch');
  }

  function run() {
    $this->update();
    $this->clear();
    $this->runTests();
    $this->restart();
    $this->sendResults();
    chdir($this->cwd);
  }

}