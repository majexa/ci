<?php

class CiCrawlerQueueWorker extends QueueWorker {

  function __construct($id) {
    parent::__construct($id);
    $this->setName('ciCrawler');
    Dir::make(__DIR__.'/web/captures');
  }

  protected function processData($url) {
    $path = str_replace('/', '-', $url);
    $path = str_replace(':', '-', $path);
    $path = trim($path, '-');
    $path = CI_PATH.'/web/captures/'.$path;
    print shell_exec("phantomjs ".NGN_ENV_PATH."/ci/phantomjs/capture.js $url $path");
    try {
      (new Image)->resampleAndSave($path.'.png', $path.'.png', 100, 200);
    } catch (Exception $e) {
    }
  }

}
