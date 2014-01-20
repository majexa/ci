<?php

/**
 * Continuous integration system//
 */
class Ci extends GitBase {

  protected $forceParam;
  protected $updatedFolders = [], $effectedTests = [];
  protected $isChanges = false;
  protected $commonMailText = '';

  function __construct($forceParam = null) {
    parent::__construct();
    $this->forceParam = $forceParam;
  }

  static $delimiter = "\n===================\n";

  protected function update() {
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return;
    }
    foreach ($folders as $folder) {
      if ((new GitFolder($folder))->reset()) {
        output("$folder: updated");
        $this->updatedFolders[] = $folder;
      }
      else {
        output("$folder: no changes");
      }
    }
    if ($this->updatedFolders) {
      $this->commonMailText = "Deploy by Continuous integration system at ".date('d.m.Y H:i:s')."\nUpdated folders:\n".implode("\n", $this->updatedFolders).self::$delimiter;
    }
  }

  protected function runTest($cmd, $param = '') {
    if (getcwd() != NGN_ENV_PATH.'/run') chdir(NGN_ENV_PATH.'/run');
    $runInitPath = '';
    if (strstr($cmd, 'ProjectTestRunner')) {
      $runner = 'site.php '.($param ? : 'test');
    }
    else {
      if ($param) $runInitPath = ' '.$param;
      $runner = 'run.php';
    }
    $testResult = $this->shellexec("php $runner \"$cmd\"$runInitPath");
    if (strstr($testResult, 'FAILURES!') or strstr($testResult, 'Fatal error') or strstr($testResult, 'fault')) $this->errorsText .= $testResult;
    if (preg_match('/<running tests: (.*)>/', $testResult, $m)) {
      $this->effectedTests = array_merge($this->effectedTests, Misc::quoted2arr($m[1]));
    }
  }

  protected function _runTests() {
    if ($this->server['sType'] != 'prod') {
      $this->runProjectsTests();
      $this->runLibTests();
    }
    if (file_exists(NGN_ENV_PATH.'/projects') and $this->server['sType'] == 'prod') {
      $this->runTest("(new TestRunner(['projectsIndexAvailable']))->global()");
    }
    $this->runTest("(new TestRunner('allErrors'))->global()");
  }

  protected function runLibTests() {
    foreach (glob(NGN_ENV_PATH.'/*', GLOB_ONLYDIR) as $f) {
      if (!file_exists("$f/.ci")) continue;
      $libFolder = file_exists("$f/lib") ? "$f/lib" : $f;
      $runInitPath = file_exists("$f/init.php") ? "$f/init.php" : $libFolder;
      $this->runTest("(new TestRunner)->local('$libFolder')", $runInitPath);
    }
  }

  function runProjectsTests() {
    if (!file_exists(NGN_ENV_PATH.'/projects')) return;
    output('Running projects tests');
    $domain = 'test.'.$this->server['baseDomain'];
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec("php pm.php localServer createProject test $domain common");
    chdir(dirname(__DIR__).'/run');
    $this->runTest('(new ProjectTestRunner)->global()');
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
    chdir(dirname(__DIR__).'/run');
    foreach (glob(NGN_ENV_PATH.'/projects/*', GLOB_ONLYDIR) as $f) {
      if (!is_dir("$f/.git")) continue;
      $project = basename($f);
      $this->runTest('(new ProjectTestRunner)->local(false)', $project); // project level specific tests. on project $project
    }
  }

  protected function runTests() {
    if (($this->updatedFolders and $this->forceParam != 'update') or $this->forceParam == 'test') {
      $this->_runTests();
    }
    else {
      output("No changes");
    }
  }

  protected function sendResults() {
    if ($this->effectedTests) $this->commonMailText .= 'Effected tests: '.implode(', ', $this->effectedTests).self::$delimiter;
    if ($this->errorsText) {
      (new SendEmail)->send('masted311@gmail.com', "Errors on {$this->server['baseDomain']}", $this->commonMailText.'<pre>'.$this->errorsText.'</pre>');
      print $this->errorsText;
    }
    else {
      if ($this->commonMailText) {
        $this->commonMailText .= "Complete successful";
        (new SendEmail)->send('masted311@gmail.com', "Deploy results on {$this->server['baseDomain']}", $this->commonMailText, false);
      }
      output("Complete successful");
    }
  }

  protected function restart() {
    //if ($this->updatedFolders or $this->forceParam == 'update') {
    $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects restart');
    //}
  }

  protected function clear() {
    chdir(dirname(__DIR__).'/run');
    Cli::shell('php run.php "(new AllErrors)->clear()"');
    if (file_exists(NGN_ENV_PATH.'/projects')) {
      $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects cc');
      //print $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects patch');
    }
  }

  protected function findCronFiles() {
    $files = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (!file_exists("$folder/.cron") or is_dir("$folder/.cron")) continue;
        $files[] = "$folder/.cron";
      }
    }
    return $files;
  }

  function updateCron() {
    $cron = '';
    foreach ($this->findCronFiles() as $file) $cron .= trim(file_get_contents($file))."\n";
    if (file_exists(NGN_ENV_PATH.'/pm')) $cron .= $this->shellexec('php ~/ngn-env/pm/pm.php localProjects cron');
    if ($this->server['sType'] != 'prod') $cron .= "15 1 * * * php ~/ngn-env/ci/update\n"; // 01:15
    $currentCron = $this->shellexec("crontab -l");
    if ($cron and $cron != $currentCron) {
      file_put_contents(__DIR__.'/temp/.crontab', $cron);
      print $this->shellexec("crontab ".__DIR__."/temp/.crontab");
    }
  }

  function projectsCommand($action) {
    $this->shellexec("php /home/user/ngn-env/pm/pm.php localProjects $action");
  }

  function run() {
    if ($this->forceParam != 'test') $this->update();
    $this->clear();
    $this->restart();
    $this->updateCron();
    $this->runTests();
    $this->sendResults();
    chdir($this->cwd);
  }

}