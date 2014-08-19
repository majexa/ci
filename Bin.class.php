<?php

class Bin {

  /**
   * Папка в которых нужно искать .bin файлы
   *
   * @var array
   */
  protected $paths;

  static $binFolder = '/usr/bin';

  function __construct(array $paths) {
    $this->paths = $paths;
    $this->checkSignature();
  }

  static function binFiles() {
    return array_filter(glob(Bin::$binFolder.'/*'), function($file) {
      return (bool)strstr(file_get_contents($file), '# ngn');
    });
  }

  function remove($all = false) {
    foreach (Bin::binFiles() as $file) {
      if (!$all and isset($this->runFiles()[File::name($file)])) continue;
      output("$file removed");
      unlink($file);
    }
  }

  protected function checkSignature() {
    foreach ($this->runFiles() as $file) {
      if (!strstr(file_get_contents($file), '# ngn')) throw new Exception("File '$file' has no NGN signature");
    }
  }

  protected function name($file) {
    return Misc::removeSuffix('.run', basename($file));
  }

  protected function add() {
    foreach ($this->runFiles() as $file) {
      $name = File::name($file);
      $newFile = Bin::$binFolder.'/'.$name;
      if (file_exists($file) and file_exists($newFile) and sha1_file($newFile) == sha1_file($file)) {
        continue;
      }
      output2("added bin: $name");
      print `sudo cp $file $newFile`;
      print `sudo chmod +x $newFile`;
    }
  }

  function runFiles() {
    $r = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (($files = glob("$folder/*.run"))) {
          foreach ($files as $file) {
            $name = File::name($file);
            if (isset($r[$name])) throw new Exception("Duplicate run file name. {$r[$name]} & $file");
            $r[$name] = $file;
          }
        }
      }
    }
    return $r;
  }

  function update() {
    $this->remove();
    $this->add();
  }

}