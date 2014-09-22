<?php


$f = new GitFolder('/home/user/ngn-env/projects/sboards');
//$f = new GitFolder('/home/user/ngn-env/casper-ngn');
print `git fetch origin 2>&1`;
//print '!'.Cli::pipeCmd('git branch -r').'!';