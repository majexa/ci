<?php

class CiCrawler extends PHPCrawler {

  function handleDocumentInfo(PHPCrawlerDocumentInfo $pageInfo) {
      print '> '.$pageInfo->url."\n";
    //(new Queue)->setName('ciCrawler')->getExchange()->publish(str_replace('http://', '', $pageInfo->url));
  }

  static function run() {
    $crawler = new CiCrawler;
    $crawler->setUrlCacheType(PHPCrawlerUrlCacheTypes::URLCACHE_MEMORY);
    $crawler->enableAggressiveLinkSearch(false);
    $crawler->addContentTypeReceiveRule('#text/html#');
    $crawler->addURLFilterRule("#\\.(jpg|jpeg|gif|png|ico)$# i");
    $crawler->addURLFilterRule("#\\.(js?|css?)(.*)$# i");
    $crawler->setPageLimit(10);
    foreach (['doc.karantin.majexa.ru'] as $url) {
    //foreach (explode("\n", trim(`pm localServer showHosts`)) as $url) {
      $crawler->setURL($url);
      $crawler->go();
      $report = $crawler->getProcessReport();
      if (PHP_SAPI == "cli") $lb = "\n";
      else $lb = "<br />";
      echo "URL: $url, followed/received/runtime: {$report->links_followed}/{$report->files_received}/{$report->process_runtime}".$lb;
      //echo "Bytes received: ".$report->bytes_received." bytes".$lb;
    }
  }

}
