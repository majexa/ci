<?php

/**
 * Continuous integration system//
 */
class Ci extends GitBase {

  protected $forceParam;
  protected $updatedFolders = [], $effectedTests = [];
  protected $isChanges = false;
  protected $commonMailText = '';

  /**
   * Приводит систему к актуальному состоянию и тестирует её
   */
  function update() {
    if ($this->forceParam != 'test') $this->_update();
    $this->clear();
    if (getOS() !== 'win') {
      $this->updateCron();
      $this->updateBin();
      $this->restart();
    }
    $this->runTests();
    $this->sendResults();
    chdir($this->cwd);
  }

  /**
   * Тестирует систему
   */
  function test() {
    $this->runTests();
  }

  /**
   * Приводит систему к актуальному состоянию
   */
  function onlyUpdate() {
    $this->_update();
  }

  static $delimiter = "\n===================\n";

  protected function _update() {
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return;
    }
    foreach ($folders as $folder) {
      output2($folder);
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

  function clean() {
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return;
    }
    foreach ($folders as $folder) {
      output2($folder);
      $f = (new GitFolder($folder));
      print $f->shellexec('git clean -f -d');
    }
  }

  protected function runTest($cmd, $param = '') {
    if (getcwd() != NGN_ENV_PATH.'/run') chdir(NGN_ENV_PATH.'/run');
    $runInitPath = '';
    $project = false;
    if (strstr($cmd, 'TestRunnerProject')) {
      $project = $param;
      $runner = 'run.php site '.$project;
    }
    else {
      if ($param) $runInitPath = ' '.$param;
      $runner = 'run.php';
    }
    $testResult = $this->shellexec("php $runner \"$cmd\"$runInitPath");
    if (strstr($testResult, 'FAILURES!') or strstr($testResult, 'Fatal error') or strstr($testResult, 'fault')) $this->errorsText .= $testResult;
    if (preg_match('/<running tests: (.*)>/', $testResult, $m)) {
      $tests = Misc::quoted2arr($m[1]);
      if ($project) foreach ($tests as &$v) $v = "$v ($project)";
      $this->effectedTests = array_merge($this->effectedTests, $tests);
    }
  }

  protected function runTests() {
    if ($this->server['sType'] != 'prod') {
      $this->runProjectsTests();
      $this->runLibTests();
    }
    if (file_exists(NGN_ENV_PATH.'/projects') and $this->server['sType'] == 'prod') {
      $this->runTest("(new TestRunnerNgn('projectsIndexAvailable'))->run()");
    }
    $this->runTest("(new TestRunnerNgn('allErrors'))->run()");
  }

  protected function runLibTests() {
    foreach (glob(NGN_ENV_PATH.'/*', GLOB_ONLYDIR) as $f) {
      if (!file_exists("$f/.ci")) continue;
      $libFolder = file_exists("$f/lib") ? "$f/lib" : $f;
      $runInitPath = file_exists("$f/init.php") ? "$f/init.php" : $libFolder;
      $this->runTest("(new TestRunnerLib('$libFolder'))->run()", $runInitPath);
    }
  }

  protected function runProjectsTests() {
    if (!file_exists(NGN_ENV_PATH.'/projects')) return;
    output('Running projects tests');
    $domain = 'test.'.$this->server['baseDomain'];
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec("php pm.php localServer createProject test $domain common");
    chdir(dirname(__DIR__).'/run');
    $this->runTest("(new TestRunnerProject('test'))->g()");
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
    chdir(dirname(__DIR__).'/run');
    foreach (glob(NGN_ENV_PATH.'/projects/*', GLOB_ONLYDIR) as $f) {
      if (!is_dir("$f/.git")) continue;
      $project = basename($f);
      $this->runTest("(new TestRunnerProject('$project'))->l()", $project); // project level specific tests. on project $project
    }
  }

  /*
  protected function runTests() {
    if (($this->updatedFolders and $this->forceParam != 'update') or $this->forceParam == 'test') {
      $this->_runTests();
    }
    else {
      output("No changes");
    }
  }
  */

  protected function sendResults() {
    if ($this->effectedTests) $this->commonMailText .= 'Effected tests: '.implode(', ', $this->effectedTests).self::$delimiter;
    $r = [
      'time' => time()
    ];
    if ($this->errorsText) {
      if (!empty($this->server['maintainer'])) {
        (new SendEmail)->send($this->server['maintainer'], "Errors on {$this->server['baseDomain']}", $this->commonMailText.'<pre>'.$this->errorsText.'</pre>');
      } else {
        output("Email not sent. Set 'maintainer' in server config");
      }
      $r['success'] = false;
      print $this->errorsText;
    }
    else {
      if ($this->commonMailText) {
        if (!empty($this->server['maintainer'])) {
          $this->commonMailText .= "Complete successful";
          (new SendEmail)->send($this->server['maintainer'], "Deploy results on {$this->server['baseDomain']}", $this->commonMailText, false);
        } else {
          output("Email not sent. Set 'maintainer' in server config");
        }
      }
      $r['success'] = true;
      output("Complete successful");
    }
    FileVar::updateVar(__DIR__.'/.last.php', $r);
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

  /**
   * Собирает крон всеми имеющимися в системе методами и заменяет им крон текущего юзера
   */
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

  protected function findBinFiles() {
    $files = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (($fs = glob("$folder/*.bin"))) foreach ($fs as $f) $files[] = $f;
      }
    }
    return $files;
  }

  /**
   * Обновляет файлы быстрого запуска в /usr/bin/
   */
  function updateBin() {
    foreach ($this->findBinFiles() as $file) {
      $newFile = '/usr/bin/'.Misc::removeSuffix('.bin', basename($file));
      if (file_exists($newFile)) {
        //output("$newFile exists. Skipped");
        //continue;
      }
      output("Creating $newFile file");
      print `sudo cp $file $newFile`;
      print `sudo chmod +x $newFile`;
    }
  }

  protected function projectsCommand($action) {
    $this->shellexec("php /home/user/ngn-env/pm/pm.php localProjects $action");
  }

  /**
   * Отображает время и статус последнего апдейта системы
   */
  function status() {
    if (!file_exists(__DIR__.'/.last.php')) return;
    $r = require __DIR__.'/.last.php';
    print date('d.m.Y H:i:s', $r['time']).': '.($r['success'] ? 'success' : 'failed')."\n";
  }

  /**
   * Отображает активные ветки всех git-проектов
   */
  function branches() {
    foreach ($this->findGitFolders() as $folder) {
      print '* '.basename($folder).":\t\t\t".(new GitFolder($folder))->wdBranch()."\n";
    }
  }

  /**
   * Показывает git-проекты, нуждающиеся в пуше или пуле
   */
  function changed() {
    foreach ($this->findGitFolders() as $folder) {
      if (!(new GitFolder($folder))->isClean()) {
        print '* '.basename($folder)."\n";
      }
    }
  }

}