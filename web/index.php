<?php

if (empty($_GET['secret']) or $_GET['secret'] != 'wegy98vwev792') return;
set_time_limit(60*10);
`ci update`;
