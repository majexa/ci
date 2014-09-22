<?php

/**
 * Непрерывная интеграция
 */
class Ci extends GitBase {

  protected $updatedFolders = [], $effectedTests = [];
  protected $isChanges = false;
  protected $commonMailText = '';

  /**
   * Приводит систему к актуальному состоянию и тестирует её
   */
  function update($forceParam = null) {
    if ($forceParam != 'test') {
      if (!$this->_update()) {
        output("no changes");
        return;
      }
    }
    if (getOS() !== 'win') {
      print `pm localProjects updateIndex`;
      $this->updateBin();
      $this->updateCron();
      $this->updateDaemons();
    }
    $this->test();
    chdir($this->cwd);
  }

  /**
   * Тестирует систему
   */
  function test() {
    $this->clearErrors();
    $this->restart();
    if ($this->server['sType'] != 'prod') {
      $this->runProjectsTests();
      $this->libTests();
    }
    if (file_exists(NGN_ENV_PATH.'/projects') and $this->server['sType'] == 'prod') {
      $this->runTest("(new TestRunnerNgn('projectsIndexAvailable'))->run()");
    }
    $this->runTest("(new TestRunnerNgn('allErrors'))->run()");
    $this->sendResults();
    $this->updateStatus();
  }

  /**
   * Приводит систему к актуальному состоянию
   */
  function onlyUpdate() {
    $this->_update();
  }

  static $delimiter = "\n===================\n";

  protected function _update() {
    $this->updateEnvPackages();
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return false;
    }
    foreach ($folders as $folder) {
      if ((new GitFolder($folder))->reset()) {
        output("$folder: updated");
        $this->updatedFolders[] = $folder;
      }
    }
    if ($this->updatedFolders) {
      $this->commonMailText = "Deploy by Continuous integration system at ".date('d.m.Y H:i:s')."\nUpdated folders:\n".implode("\n", $this->updatedFolders).self::$delimiter;
    }
    return (bool)$this->updatedFolders;
  }

  /**
   * "git reset" для всех проектов
   */
  function reset() {
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return;
    }
    foreach ($folders as $folder) {
      if ((new GitFolder($folder))->resetToRemote('origin', true)) output("$folder: reset");
    }
  }

  protected function updateEnvPackages() {
    if (!isset($this->server['git'])) {
      output('update env packages skiped. set server config "git" value');
      return;
    }
    chdir(NGN_ENV_PATH);
    foreach ($this->getEnvPackages() as $package) {
      if (!file_exists(NGN_ENV_PATH.'/'.$package)) {
        output("cloning $package");
        print `git clone {$this->server['git']}/$package`;
      }
    }
  }

  /*
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
  */

  protected function runTest($cmd, $project = null, $runInitPath = '') {
    if (getcwd() != NGN_ENV_PATH.'/run') chdir(NGN_ENV_PATH.'/run');
    if ($project) {
      $runner = 'run.php site '.$project;
    }
    else {
      $runner = 'run.php';
    }
    $testResult = $this->shellexec("php $runner \"$cmd\"".($runInitPath ? ' '.$runInitPath : ''), true);
    if (($error = TestCore::detectError($testResult))) $this->errors[] = [$testResult, $error];
    if (preg_match('/<running tests: (.*)>/', $testResult, $m)) {
      if (($tests = Misc::quoted2arr($m[1]))) {
        $runInitPathPrefix = $runInitPath ? $runInitPath.'|' : $runInitPath;
        if ($project) foreach ($tests as &$v) $v = Misc::removePrefix('Test', $v)." ({$runInitPathPrefix}$project)";
        $this->effectedTests = array_merge($this->effectedTests, $tests);
        print $testResult;
      }
    }
  }

  protected $errors = [];

  protected function shellexec($cmd, $output = true) {
    $r = Cli::shell($cmd, $output);
    if (preg_match('/(?<!all)error/i', $r)) $this->errors[] = [$r, '"error" text in shell output of cmd: '.$cmd];
    if (preg_match('/(?<!all)fatal/i', $r)) $this->errors[] = [$r, '"error" text in shell output of cmd: '.$cmd];
    return $r;
  }

  function libTests() {
    $libFolders = [];
    foreach (glob(NGN_ENV_PATH.'/*', GLOB_ONLYDIR) as $folder) {
      if (!file_exists("$folder/.ci")) continue;
      $libFolders[] = $folder;
    }
    output('Found: '.implode(', ', array_map('basename', $libFolders)));
    foreach ($libFolders as $folder) {
      $folder = file_exists("$folder/lib") ? "$folder/lib" : $folder;
      $runInitPath = file_exists("$folder/init.php") ? "$folder/init.php" : $folder;
      $this->runTest("(new TestRunnerLib('$folder'))->run()", null, $runInitPath);
    }
  }

  function projectTestCommon() {
    $domain = 'test.'.$this->server['baseDomain'];
    $this->shellexec("pm localServer createProject test $domain common");
    $this->runTest("(new TestRunnerProject('test'))->g()", 'test');
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
  }

  function projectTestSb() {
    $domain = 'test.'.$this->server['baseDomain'];
    $this->shellexec("pm localServer createProject test $domain sb");
    $this->runTest("(new TestRunnerPlib('test', 'sb'))->run()", 'test', 'sb');
    chdir(dirname(__DIR__).'/pm');
    $this->shellexec('php pm.php localProject delete test');
  }

  function projectLocalTests() {
    chdir(dirname(__DIR__).'/run');
    foreach (glob(NGN_ENV_PATH.'/projects/*', GLOB_ONLYDIR) as $f) {
      if (!is_dir("$f/.git")) continue;
      $project = basename($f);
      $this->runTest("(new TestRunnerProject('$project'))->l()", $project); // project level specific tests. on project $project
    }
  }

  protected function runProjectsTests() {
    if (!file_exists(NGN_ENV_PATH.'/projects')) return;
    $this->projectTestCommon();
    $this->projectTestSb();
    $this->projectLocalTests();
  }

  protected function sendResults() {
    if ($this->effectedTests) $this->commonMailText .= 'Effected tests: '.implode(', ', $this->effectedTests).self::$delimiter;
    if ($this->errors) {
      $err = '';
      foreach ($this->errors as $v) $err .= $v[0];
      if (!empty($this->server['maintainer'])) {
        (new SendEmail)->send($this->server['maintainer'], "Errors on {$this->server['baseDomain']}", $this->commonMailText.'<pre>'.$err.'</pre>');
      }
      else {
        output("Email not sent. Set 'maintainer' in server config");
      }
      print $err;
    }
    else {
      if ($this->commonMailText) {
        if (!empty($this->server['maintainer'])) {
          $this->commonMailText .= "Complete successful";
          (new SendEmail)->send($this->server['maintainer'], "Deploy results on {$this->server['baseDomain']}", $this->commonMailText, false);
        }
        else {
          output("Email not sent. Set 'maintainer' in server config");
        }
      }
      output("Complete successful. ".'Effected tests: '.implode(', ', $this->effectedTests));
    }
  }

  protected function updateStatus() {
    $r = ['time' => time()];
    $r['success'] = count($this->errors);
    FileVar::updateVar(__DIR__.'/.status.php', $r);
  }

  protected function restart() {
    $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects restart');
  }

  protected function clearErrors() {
    chdir(dirname(__DIR__).'/run');
    Cli::shell('php run.php "(new AllErrors)->clear()"');
    if (file_exists(NGN_ENV_PATH.'/projects')) {
      $this->shellexec('php /home/user/ngn-env/pm/pm.php localProjects cc');
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
    if (file_exists(NGN_ENV_PATH.'/pm')) $cron .= `pm localServer cron`;
    if ($this->server['sType'] != 'prod') $cron .= "30 4 * * * ci update >> /home/user/ngn-env/logs/cron 2>&1\n";
    // $cron .= "* * * * * env > /home/user/ngn-env/logs/cron.env\n";
    $currentCron = $this->shellexec("crontab -l", false);
    Errors::checkText($cron);
    if ($cron and $cron != $currentCron) {
      file_put_contents(__DIR__.'/temp/.crontab', $cron);
      print $this->shellexec("crontab ".__DIR__."/temp/.crontab");
      print "cron updated:\n--------\n$cron";
    }
  }

  /**
   * Обновляет файлы быстрого запуска
   */
  function updateBin() {
    print `sudo ci _updateBin`;
  }

  /**
   * Удаляет файлы быстрого запуска
   */
  function removeBin() {
    print `sudo ci _removeBin`;
  }

  /**
   * Показывает все демоны
   */
  function showDaemons() {
    print '* '.implode("\n* ", (new Daemons)->r)."\n";
  }

  /**
   * Обновляет демоны
   */
  function updateDaemons() {
    $files = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        foreach (glob("$folder/*.php") as $file) {
          if ($file == __FILE__) continue;
          if (!strstr(file_get_contents($file), '// ngn-daemon')) continue;
          $files[] = $file;
        }
      }
    }
    foreach ($files as $file) {
      $projectName = basename(dirname($file));
      $daemonName = basename(File::stripExt($file));
      (new DaemonInstaller($projectName, $daemonName))->install();
    }
  }

  function _updateBin() {
    (new Bin($this->paths))->update();
  }

  protected function _removeBin() {
    (new Bin($this->paths))->remove();
  }

  protected function projectsCommand($action) {
    $this->shellexec("php /home/user/ngn-env/pm/pm.php localProjects $action");
  }

  /**
   * Отображает время и статус последнего апдейта системы
   */
  function status() {
    if (file_exists(__DIR__.'/.status.php')) {
      if (($r = require __DIR__.'/.status.php')) {
        print date('d.m.Y H:i:s', $r['time']).': '.($r['success'] ? 'success' : 'failed')."\n";
      }
    }
  }

  protected function getEnvPackages() {
    if (file_exists(__DIR__.'/.packages.php')) {
      return require __DIR__.'/.packages.php';
    }
    else {
      return [];
    }
  }

  function packages() {
    if (($r = $this->getEnvPackages())) {
      print 'Packages: '.implode(', ', $r)."\n";
    }
  }

  function installPackage($name) {
    chdir(NGN_ENV_PATH);
    print `git clone https://github.com/masted/$name`;
  }

  function installPackages() {
    if (($r = require __DIR__.'/.packages.php')) {
      foreach ($r as $name) {
        chdir(NGN_ENV_PATH);
        if (file_exists(NGN_ENV_PATH."/$name")) {
          output("Package $name exists. Skipped");
          continue;
        }
        output("Cloning $name");
        print `git clone https://github.com/masted/$name`;
      }
    }
  }

}
