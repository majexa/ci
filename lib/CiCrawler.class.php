<?php

class CiCrawler extends PHPCrawler {

  function handleDocumentInfo(PHPCrawlerDocumentInfo $pageInfo) {
    (new Queue)->setName('ciCrawler')->getExchange()->publish(str_replace('http://', '', $pageInfo->url));
  }

  static function run() {
    foreach (explode("\n", trim(`pm localServer showHosts`)) as $url) {
      $crawler = new CiCrawler;
      $crawler->setUrlCacheType(PHPCrawlerUrlCacheTypes::URLCACHE_MEMORY);
      $crawler->setURL($url);
      $crawler->addContentTypeReceiveRule('#text/html#');
      $crawler->addURLFilterRule("#\\.(jpg|jpeg|gif|png|ico)$# i");
      $crawler->addURLFilterRule("#\\.(js?|css?)(.*)$# i");
      $crawler->setPageLimit(10);
      $crawler->go();
      $report = $crawler->getProcessReport();
      if (PHP_SAPI == "cli") $lb = "\n";
      else $lb = "<br />";
      echo "Summary:".$lb;
      echo "Links followed: ".$report->links_followed.$lb;
      echo "Documents received: ".$report->files_received.$lb;
      echo "Bytes received: ".$report->bytes_received." bytes".$lb;
      echo "Process runtime: ".$report->process_runtime." sec".$lb;
    }
  }

}
