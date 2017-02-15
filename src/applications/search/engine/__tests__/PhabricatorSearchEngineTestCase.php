<?php

final class PhabricatorSearchEngineTestCase extends PhabricatorTestCase {

  public function testLoadAllEngines() {
    $services = PhabricatorSearchCluster::getAllServices();
    $this->assertTrue(!empty($services));
  }

}
