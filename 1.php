<?php

$ci = new Ci;
try {
  $ci->runTest('lib smon');
} catch (Exception $e) {
  print $e->getMessage();
}
