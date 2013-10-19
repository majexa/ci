<?php

require __DIR__.'/CiBase.class.php';
require __DIR__.'/CiDev.class.php';
(new CiDevDir)->startDev('dev2')->{$_SERVER['argv'][1]}($_SERVER['argv'][2]);
