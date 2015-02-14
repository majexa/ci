<?php

// ngn-daemon

define('CI_PATH', __DIR__);

(new CiCrawlerQueueWorker(isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 1))->run();



