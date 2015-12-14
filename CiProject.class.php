<?php

class CiProject {

  function copy($serverName) {
    $server = require NGN_ENV_PATH."/config/remoteServers/$serverName.php";
    $server['host'];
    $server['sshUser'];
    ``;
  }

}