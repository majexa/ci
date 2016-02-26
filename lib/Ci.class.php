<?php

/**
 * Непрерывная интеграция
 */
class Ci extends GitBase {

  static $outputOnlyTestResult = false;

  protected $updatedFolders = [], $effectedTests = [];
  protected $isChanges = false;
  protected $commonMailText = '';

  function __construct() {
    parent::__construct();
    Dir::clear(Ci::$tempFolder, true);
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

  /**
   * Приводит систему к актуальному состоянию и тестирует её
   */
  function update($forceUpdate = false) {
    if ($this->server['sType'] == 'ci') {
      // ci server logic
      if (file_exists(__DIR__.'/.updating')) {
        if ($forceUpdate) {
          unlink(__DIR__.'/.updating');
        } else {
          print "update in progress\n";
          return;
        }
      }
      touch(__DIR__.'/.updating');
      if (!$this->_update()) {
        output("no changes");
        if (!$forceUpdate) return;
      }
      print `ci test`;
      unlink(__DIR__.'/.updating');
    } else {
      // another server types logic
      $this->_update();
    }
  }

  /**
   * Запускает все существующие в ngn-среде тесты и отправляет email с отчетом
   */

  function test() {
    $this->_cleanStatus();
    $this->_deleteRemovedBranches();
    $this->_testIssues();
    $this->_testMaster();
    //$this->sendReport();
  }

  function _test() {
    try {
      $this->cleanup();
      if (getOS() !== 'win') {
        print `pm localProjects updateIndex`;
        $this->updateBin();
        $this->updateCron();
        $this->updateDaemons();
        $this->updateScripts();
        print `pm localProjects cmd update 1`;
      }
      $this->restart();
      if ($this->server['sType'] != 'prod') {
        $this->runProjectsTests();
        $this->cst();
        $this->libTests();
      }
//      if (file_exists(NGN_ENV_PATH.'/projects') and $this->server['sType'] == 'prod') {
//        $this->runTest('ngn projectsIndexAvailable');
//      }
      //`run ci/crawler ci,crawl`;
      $this->runTest('ngn allErrors');
    } catch (Exception $e) {
      $this->errors = $e->getMessage();
    }
    $this->updateStatus();
    $this->outputReport();
  }

  /**
   * Собирает проекты
   */
  function build() {
    print `pm localProjects cmd "(new SflmBuild)->run()"`;
    //$this->runTest('ngn allErrors');
  }

  /**
   * Комитит и пушит проекты
   */
  function release() {
    output2("Pushing projects");
    foreach (glob(NGN_ENV_PATH.'/projects/*') as $f) {
      if (file_exists("$f/.nonNgn")) continue;
      if (!file_exists("$f/.git")) continue;
      $folder = new GitFolder($f);
      $folder->commit('Release '.date('d.m.Y H:i:s'));
      $folder->push();
    }
  }

  /**
   * @throws Exception
   */
//  protected function _release_() {
//    $this->update(true);
//    if ($this->errors) {
//      output3('release aborted');
//      return;
//    }
//    $this->build();
//    $this->deploy();
//  }

//  function deploy() {
//    $serverConfig = require NGN_ENV_PATH.'/config/server.php';
//    if (empty($serverConfig['deployServers'])) {
//      throw new Exception('There are no deploy servers');
//    }
//    foreach ($serverConfig['deployServers'] as $host) {
//      print `ssh user@$host ci update`;
//    }
//  }

  /**
   * Запускает client-side тесты для проектов
   */
  function cst() {
    print `pm localServer deleteProject test`;
    print `pm localServer createTestProject common`;
    print `run ngn-cst/cmd/update ngn-cst`;
    // common tests on "test" project
    foreach (Dir::getFilesR(NGN_ENV_PATH.'/ngn-cst/casper/test') as $f) {
      $f = Misc::removeSuffix('.json', str_replace(NGN_ENV_PATH.'/ngn-cst/casper/test/', '', $f));
      $this->_cst('test', $f);
    }
    // local project tests
    foreach (glob(NGN_ENV_PATH.'/projects/*', GLOB_ONLYDIR) as $f) {
      if (file_exists("$f/.nonNgn")) continue;
      if (file_exists("$f/.keepIndex")) continue;
      /**
       * @doc ci
       * ##Выключение client-side тестирования##
       *
       * Для того, что бы выключить client-side тестирование при выполнении таких
       * команд, как `ci update`, `ci test`, `ci release` необходимо создать пустой
       * файл `.keepIndex` в корне проекта
       */
      if (file_exists("$f/.cstDisable")) continue;
      $projectName = basename($f);
      $this->_cst($projectName, 'index');
      if (!file_exists("$f/site/casper")) continue;
      foreach (glob("$f/site/casper/test/*.json") as $script) {
        $testName = str_replace('.json', '', basename($script));
        $this->_cst($projectName, $testName);
      }
    }
  }

  protected function _cst($projectName, $testName) {
    $o = [];
    print `pm localProject replaceConstant $projectName more BUILD_MODE true`;
    exec("cst $projectName $testName", $o, $code);
    if ($code) throw new Exception(implode("\n", $o));
    $this->effectedTests[] = str_replace(NGN_ENV_PATH.'/projects/', '', "cst: $projectName/$testName");
  }

  /**
   * Запускает .update скрипты, найденные во всех ngn-env базовых путях
   */
  function updateScripts() {
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (!($files = glob("$folder/*.update"))) continue;
        foreach ($files as $file) {
          print `bash $file`;
        }
      }
    }
  }

  /**
   * Приводит систему к актуальному состоянию
   */
  function onlyUpdate() {
    $this->_update();
  }

  static $delimiter = "\n===================\n\n";

  /**
   * Обновляет git-каталоги и приводит их к мастеру
   *
   * @return bool
   */
  protected function _update() {
    $this->installEnvPackages();
    $this->_fetch();
    if (!($folders = $this->findGitFolders())) {
      output("No git folders found");
      return false;
    }
    foreach ($folders as $folder) {
      $gitFolder = new GitFolder($folder);
      `git checkout master`;
      if ($gitFolder->reset()) {
        output("$folder: updated");
        $this->updatedFolders[] = $folder;
      }
    }
    if ($this->updatedFolders) {
      $this->commonMailText = "Deploy by Continuous integration system at ".date('d.m.Y H:i:s')."\nUpdated folders:\n".implode("\n", $this->updatedFolders).self::$delimiter;
    }
    print `run cc`;
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
      (new GitFolder($folder))->resetToRemote('origin', true);
    }
  }

  protected function installEnvPackages() {
    if (!isset($this->server['git'])) {
      output('install env packages skiped. set server config "git" value');
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

  function runTest($subCmd) {
    return $this->shellexecTest($subCmd);
  }

  protected $errors = '';

  /**
   * @param $subCmd
   * @param array $options
   *                       - Closure $testNamesMapper(array $testClasses) Ф-я возвращающая название теста и принимающая в качестве аргумента имя его класса
   * @return string
   * @throws Exception
   */
  protected function shellexecTest($subCmd, array $options = []) {
    if (getcwd() != NGN_ENV_PATH.'/run') chdir(NGN_ENV_PATH.'/run');
    $cmd = 'tst '.$subCmd;
    $testCheckFile = Ci::$tempFolder.'/tst'.md5($subCmd);
    touch($testCheckFile);
    $testTextResult = Cli::shell("$cmd; rm $testCheckFile", false);
    // Если выполнение тестовой команды оборвалось нештатно
    if (file_exists($testCheckFile)) throw new Exception('Test command "'.$subCmd.'" aborted. '."Command result:\n".$testTextResult);
    // Парсим имена выполненных тестов
    if (preg_match('/<running tests: (.*)>/', $testTextResult, $m)) {
      if (($tests = Misc::quoted2arr($m[1]))) {
        if (isset($options['testNamesMapper'])) {
          $tests = $options['testNamesMapper']($tests);
        }
        $this->effectedTests = array_merge($this->effectedTests, $tests);
      }
    }
    // Если в тексте теста есть слова-ошибки
    if ($error = TestCore::detectError($testTextResult)) {
      // убираем всё, что не относится непосредственно к отчёту
      $pos = strpos($testTextResult, '<-t=t->') + strlen('<-t=t->');
      $testTextResult = substr($testTextResult, $pos, strlen($testTextResult));
      $pos = strpos($testTextResult, '1)') + 3;
      $rrrr = substr($testTextResult, $pos, strpos($testTextResult, 'FAILURES!')-$pos-2);
      //throw new Exception($error.":\n".$rrrr);
      throw new Exception($rrrr);
    }
    if (preg_match('/(?<!all)error/i', $testTextResult)) throw new Exception('"error" text in shell output of cmd: '.$cmd."\noutput:\n$testTextResult");
    if (preg_match('/(?<!all)fatal/i', $testTextResult)) throw new Exception('"error" text in shell output of cmd: '.$cmd."\noutput:\n$testTextResult");
    return $testTextResult;
  }

  protected function shellexec($cmd, $output = true) {
    if (self::$outputOnlyTestResult) $output = false;
    $r = Cli::shell($cmd, $output);
    if (preg_match('/(?<!all)error/i', $r)) throw new Exception('"error" text in shell output of cmd: '.$cmd."\noutput:\n$r");
    if (preg_match('/(?<!all)fatal/i', $r)) throw new Exception('"error" text in shell output of cmd: '.$cmd."\noutput:\n$r");
    return $r;
  }

  /**
   * Запускает тесты stand-alone библиотек
   */
  function libTests() {
    $libFolders = [];
    foreach (glob(NGN_ENV_PATH.'/*', GLOB_ONLYDIR) as $folder) {
      if (!file_exists("$folder/.ci")) continue;
      $libFolders[] = $folder;
    }
    output('Found libs for testing: '.implode(', ', array_map('basename', $libFolders)));
    foreach ($libFolders as $folder) {
      $libName = basename($folder);
      $this->shellexecTest('lib '.$libName, [
        'testNamesMapper' => function(array $testClasses) use ($libName) {
          foreach ($testClasses as &$testClass) {
            $testClass = "lib/$libName:".$testClass;
          }
          return $testClasses;
        }
      ]);
    }
  }

  function _libTests() {
    try {
      $this->libTests();
    }  catch (Exception $e) {
      $this->errors = $e->getMessage();
    }
    $this->updateStatus();
  }

  protected function serverHasProjectsSupport() {
    return file_exists(NGN_ENV_PATH.'/projects');
  }

  /**
   * Запускает глобальные тесты на тестовом проекте
   */
  function projectTestCommon() {
    if (!$this->serverHasProjectsSupport()) return;
    $this->runTest('proj g test');
    chdir(NGN_ENV_PATH.'/pm');
    //$this->shellexec('pm localProject delete test');
  }

  /**
   * Запускает тесты SiteBuilder'а
   */
//  function projectTestSb() {
//    if (!$this->serverHasProjectsSupport()) return;
//    $domain = 'test.'.$this->server['baseDomain'];
//    $this->shellexec("pm localServer createProject test $domain sb");
//    $this->runTest("(new TestRunnerPlib('test', 'sb'))->run()", 'test', 'sb');
//    chdir(dirname(__DIR__).'/pm');
//    $this->shellexec('php pm.php localProject delete test');
//  }

  /**
   * Запускает локальные проектные тесты на всех проектах
   */
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
    print `pm localServer deleteProject test`;
    $this->projectTestCommon();
    //$this->projectTestSb();
    //$this->projectLocalTests();
  }

  protected function sendReport() {
    if ($this->effectedTests) $this->commonMailText .= 'Effected tests: '.implode(', ', $this->effectedTests).self::$delimiter;
    if ($this->errors) {
      if (!empty($this->server['maintainer'])) {
        (new SendEmail)->send($this->server['maintainer'], "Errors on {$this->server['baseDomain']}", '<pre>'.$this->commonMailText.$this->errors.'</pre>');
      }
      else {
        output("Email not sent. Set 'maintainer' in server config");
      }
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

  protected function outputReport() {
    if ($this->errors) {
      print $this->errors;
    } else {
      output("Complete successful. ".'Effected tests: '.implode(', ', $this->effectedTests));
    }
  }

  protected function updateStatus() {
    $r = ['time' => time()];
    if (empty($this->errors)) {
      $r['success'] = true;
    } else {
      $r['success'] = false;
      $r['errors'] = $this->errors;
    }
    FileVar::updateSubVar(__DIR__.'/.status.php', $this->masterBranch, $r);
  }

  protected function restart() {
    $this->shellexec('php '.NGN_ENV_PATH.'/pm/pm.php localProjects restart');
  }

  /**
   * Удаляет логи с ошибками и чистит кэш на всех проекта
   *
   * @throws Exception
   */
  function cleanup() {
    $this->errors = '';
    chdir(NGN_ENV_PATH.'/run');
    `run cc`;
    `pm cc`;
    $this->shellexec('php run.php "(new AllErrors)->clear()"');
    if (file_exists(NGN_ENV_PATH.'/projects')) {
      $this->shellexec('php '.NGN_ENV_PATH.'/pm/pm.php localProjects cc', false);
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

  protected function cronRenderContents($file) {
    $c = file_get_contents($file);
    if (strstr($c, '{cmd}')) {
      $folder = dirname($file);
      if (!file_exists("$folder/cmd.php")) {
        throw new Exception("U can't use {cmd} string without cmd.php file in '$folder' folder");
      }
      $c = str_replace('{cmd}', "php $folder/cmd.php", $c);
    }
    return trim($c);
  }

  /**
   * Собирает крон всеми имеющимися в системе методами и заменяет им крон текущего юзера
   */
  function updateCron($debug = false) {
    $cron = '';
    foreach ($this->findCronFiles() as $file) {
      $c = $this->cronRenderContents($file);
      if ($debug) {
        output2($file);
        output($c);
      }
      $cron .= "$c\n";
    }
    if (file_exists(NGN_ENV_PATH.'/pm')) $cron .= $this->shellexec('pm localServer cron');
    if ($this->server['sType'] == 'ci') {
      $cron .= "0,30 * * * * ci update 1 >> ".NGN_ENV_PATH."/logs/cron 2>&1\n";
    }
    $currentCron = $this->shellexec("crontab -l", false);
    Errors::checkText($cron);
    if ($cron and $cron != $currentCron) {
      file_put_contents(Ci::$tempFolder.'/.crontab', $cron);
      print $this->shellexec('crontab '.Ci::$tempFolder.'/.crontab');
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
   *
   * Сканирует каталоги /home/user, /home/user/ngn-env, /home/user/ngn-env/projects.
   * Находит файлы с расширением .php в которых есть комментарий "// ngn-daemon".
   * Инсталлирует демон с именем folder-name, где folder - каталог с найденным файлом, а
   * name - имя файла.
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
      (new Daemon($projectName, $daemonName))->install();
    }
  }

  function _updateBin() {
    (new Bin($this->paths))->update();
  }

  protected function _removeBin() {
    (new Bin($this->paths))->remove();
  }

  protected function projectsCommand($action) {
    $this->shellexec("php ".NGN_ENV_PATH."/pm/pm.php localProjects $action");
  }

  protected function getEnvPackages() {
    if (file_exists(__DIR__.'/.packages.php')) {
      return require __DIR__.'/.packages.php';
    }
    else {
      return [];
    }
  }

  /**
   * Отображает ngn-env пакеты для этого сервера
   */
  function packages() {
    if (($r = $this->getEnvPackages())) {
      print 'Packages: '.implode(', ', $r)."\n";
    }
  }

  /**
   * Устанавливает ngn-env пакет на этом сервера
   */
  function installPackage($name) {
    chdir(NGN_ENV_PATH);
    print `git clone https://github.com/masted/$name`;
  }

  /**
   * Устанавливает ngn-env пакеты указанные в .packages.php на этом сервере
   */
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

  function _fetch() {
    output("Fetching all git folders");
    foreach ($this->findGitFolders() as $folder) {
      output2('Fetch '.$folder);
      chdir($folder);
      if (($r = `git fetch -p`)) {
        output3("git fetch -p for $folder");
        print $r;
      }
    }
  }

  function _deleteRemovedBranches() {
    foreach ((new IssueBranchFolders)->getRemoved() as $issueId => $folders) {
      foreach ($folders as $folder) {
        chdir($folder);
        output3("delete branch i-$issueId at folder $folder");
        print `git checkout master`;
        print `git branch -D i-$issueId`;
      }
    }
  }

  function checkoutMaster() {
    foreach ($this->findGitFolders() as $folder) {
      if ((new GitFolder($folder))->currentBranch() != 'master') {
        output2("checkout $folder");
        chdir($folder);
        print `git checkout master`;
      }
    }
    $this->masterBranch = 'master';
  }

  function _testIssues() {
    foreach (array_keys((new IssueBranchFolders)->getAll()) as $issueId) {
      output3("checkout $issueId");
      $this->checkoutIssue($issueId);
      $this->_test();
    }
  }

  function _testMaster() {
    output3("checkout master");
    $this->checkoutMaster();
    $this->_test();
  }

  function checkoutIssue($issueId) {
    foreach ((new IssueBranchFolders)->getAll()[$issueId] as $folder) {
      output2("Checkouting folder '$folder'");
      chdir($folder);
      print `git checkout i-$issueId`;
    }
    $this->masterBranch = 'i-'.$issueId;
  }

  function _cleanStatus() {
    FileVar::updateVar(__DIR__.'/.status.php', []);
  }

  static $tempFolder;

}

Ci::$tempFolder = dirname(__DIR__).'/temp';
