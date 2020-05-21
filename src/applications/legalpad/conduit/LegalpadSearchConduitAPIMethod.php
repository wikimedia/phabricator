<?php
final class LegalpadSearchConduitAPIMethod
  extends PhabricatorSearchEngineAPIMethod {

  public function getAPIMethodName() {
    return 'legalpad.search';
  }

  public function newSearchEngine() {
    return new LegalpadDocumentSearchEngine();
  }

  public function getMethodSummary() {
    return pht('Read information about legalpad documents.');
  }

}
