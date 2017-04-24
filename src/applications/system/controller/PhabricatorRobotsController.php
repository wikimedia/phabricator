<?php

final class PhabricatorRobotsController extends PhabricatorController {

  public function shouldRequireLogin() {
    return false;
  }

  public function processRequest() {
    $out = array();

    // Prevent indexing of '/diffusion/', since the content is not generally
    // useful to index, web spiders get stuck scraping the history of every
    // file, and much of the content is Ajaxed in anyway so spiders won't even
    // see it. These pages are also relatively expensive to generate.

    // Note that this still allows commits (at '/rPxxxxx') to be indexed.
    // They're probably not hugely useful, but suffer fewer of the problems
    // Diffusion suffers and are hard to omit with 'robots.txt'.

    $out[] = 'User-Agent: *';
    $out[] = 'Disallow: /diffusion/';
    $out[] = 'Disallow: /source/';
    $out[] = 'Disallow: /multimeter/';
    $out[] = 'Disallow: /file/';
    $out[] = 'Disallow: /project/sprint';
    $out[] = 'Disallow: /policy/explain';
    $out[] = 'Disallow: /auth';
    $out[] = 'Disallow: /login';
    $out[] = 'Disallow: /maniphest/transaction';
    $out[] = 'Disallow: /tag';
    $out[] = 'Disallow: /search/query/all';
    $out[] = 'Disallow: /conduit';
    $out[] = 'Disallow: /api';
    $out[] = 'Disallow: /project';
    $out[] = 'Disallow: /applications';
    $out[] = 'Disallow: /token';
    $out[] = 'Disallow: /pholio';
    $out[] = 'Disallow: /dashboard';
    $out[] = 'Disallow: /calendar';
    $out[] = 'Disallow: /herald';


    // Add a small crawl delay (number of seconds between requests) for spiders
    // which respect it. The intent here is to prevent spiders from affecting
    // performance for users. The possible cost is slower indexing, but that
    // seems like a reasonable tradeoff, since most Phabricator installs are
    // probably not hugely concerned about cutting-edge SEO.
    $out[] = 'Crawl-delay: 1';

    $content = implode("\n", $out)."\n";

    return id(new AphrontPlainTextResponse())
      ->setContent($content)
      ->setCacheDurationInSeconds(phutil_units('2 hours in seconds'))
      ->setCanCDN(true);
  }
}
