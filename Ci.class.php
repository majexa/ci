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

  protected $updatedFolders = [];
  protected $isChanges = false;

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
      $this->commonMailText = "Deploy by Continuous integration system at ".date('d.m.Y H:i:s')."\nUpdated folders:\n".implode("\n", $this->updatedFolders)."\n===================\n\n";
    }
  }

  protected function runTest($cmd) {
    $testResult = $this->shellexec($cmd);
    if (strstr($testResult, 'FAILURES!') or strstr($testResult, 'Fatal error') or strstr($testResult, 'fault')) $this->errorsText .= $testResult;
  }

  protected $commonMailText = '';

  protected function _runTests() {
    if ($this->server['sType'] != 'prod') {
      $this->runProjectsTests();
      chdir(NGN_ENV_PATH.'/run');
      foreach (glob(NGN_ENV_PATH.'/*', GLOB_ONLYDIR) as $f) if (file_exists("$f/.ci")) {
        $folderName = basename($f);
        print `php ~/ngn-env/run/run.php "(new TestRunner)->local('$folderName')" $f`;
      }
    }
    if (file_exists(NGN_ENV_PATH.'/projects')) {
      // Если это сервер с проектами
      if ($this->server['sType'] == 'prod') {
        // Для продакшена запускаем только эти тесты
        $this->runTest('php run.php "(new TestRunner([\'projectsIndexAvailable\', \'allErrors\']))->global()"');
      } else {
        $this->runTest('php run.php "(new TestRunner)->global()"');
      }
    }
    else $this->runTest('php run.php "(new TestRunner(\'allErrors\'))->global()"');
  }

  protected function runProjectsTests() {
    if (!file_exists(NGN_ENV_PATH.'/projects')) return;
    $domain = 'test.'.$this->server['baseDomain'];
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec("php pm.php localServer createProject test $domain common");
    chdir(dirname(__DIR__).'/run');
    $this->runTest('php site.php test "(new ProjectTestRunner)->global()"');
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
    chdir(dirname(__DIR__).'/run');
    foreach (glob(NGN_ENV_PATH.'/projects/*', GLOB_ONLYDIR) as $f) {
      if (!is_dir("$f/.git")) continue;
      $project = basename($f);
      $this->runTest('php site.php '.$project.' "(new ProjectTestRunner)->local()"'); // project level specific tests. on project $project
    }
  }

  protected function runTests() {
    if (($this->updatedFolders and $this->forceParam != 'update') or $this->forceParam == 'test') {
      $this->_runTests();
    }
    else {
      output("no changes");
    }
  }

  protected function sendResults() {
    if ($this->errorsText) {//
      (new SendEmail)->send('masted311@gmail.com', "Errors on {$this->server['baseDomain']}", '<pre>'.$this->commonMailText.$this->errorsText.'</pre>');
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
    $cron .= $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects cron');
    if ($this->server['sType'] != 'prod') $cron .= "15 1 * * * php /home/user/ngn-env/ci/update\n"; // 01:15
    $currentCron = $this->shellexec("crontab -l");
    if ($cron and $cron != $currentCron) {
      file_put_contents('/tmp/.crontab', $cron);
      print $this->shellexec("crontab /tmp/.crontab");
    }
  }

  function projectsCommand($action) {
    $this->shellexec("php /home/user/ngn-env/pm/pm.php localProjects $action");
  }

  function run() {
    $this->update();
    $this->clear();
    $this->runTests();
    $this->restart();
    $this->updateCron();
    $this->sendResults();
    chdir($this->cwd);
  }

}