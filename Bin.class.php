<?php

class Bin {

  protected $paths, $binFolder = '/usr/local/bin';

  function __construct(array $paths) {
    $this->paths = $paths;
  }

  protected function cleanup() {
    $ngnBinFiles = array_filter(glob($this->binFolder.'/*'), function($file) {
      return (bool)strstr(file_get_contents($file), '# ngn');
    });
    $names = array_map(function($file) {
      return $this->name($file);
    }, $ngnBinFiles);
    $nonExistingNgnBinFiles = array_filter(glob($this->binFolder.'/*'), function($file) use ($ngnBinFiles, $names) {
      return in_array($file, $ngnBinFiles) and !in_array(basename($file), $names);
    });
    die2($nonExistingNgnBinFiles);
  }

  protected function fixSignature() {
    foreach ($this->files() as $file) {
      $c = file_get_contents($file);
      if (!strstr($c, '# ngn')) file_put_contents($file, $c."\n# ngn");
    }
  }

  protected function name($file) {
    return Misc::removeSuffix('.bin', basename($file));
  }

  protected function add() {
    foreach ($this->files() as $file) {
      $name = $this->name($file);
      $newFile = $this->binFolder.'/'.$name;
      if (file_exists($file) and sha1_file($newFile) == sha1_file($file)) {
        // output("$newFile exists. Skipped");
        continue;
      }
      output2("added bin: $name");
      print `sudo cp $file $newFile`;
      print `sudo chmod +x $newFile`;
    }
  }

  protected function files() {
    $files = [];
    foreach ($this->paths as $path) {
      foreach (glob("$path/*", GLOB_ONLYDIR) as $folder) {
        if (($fs = glob("$folder/*.bin"))) foreach ($fs as $f) $files[] = $f;
      }
    }
    return $files;
  }

  function update() {
    $this->cleanup();
    return;
    $this->fixSignature();
    $this->add();
  }

}