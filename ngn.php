<?php

putenv('HELP_DISABLE_DESCRIPTION=1'); // uses in CliHelp class
foreach ((new Bin([NGN_ENV_PATH]))->runFiles() as $file) {
  $name = File::name($file);
  if ($name == 'ngn') continue;
  print `$name`;
}