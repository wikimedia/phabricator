<?php

final class PhabricatorSearchConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Search');
  }

  public function getDescription() {
    return pht('Options relating to Search.');
  }

  public function getIcon() {
    return 'fa-search';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getOptions() {
    return array(
      $this->newOption('search.elastic.host', 'string', null)
        ->setLocked(true)
        ->setDescription(pht('Elastic Search host.'))
        ->addExample('http://elastic.example.com:9200/', pht('Valid Setting')),
      $this->newOption('search.elastic.namespace', 'string', 'phabricator')
        ->setLocked(true)
        ->setDescription(pht('Elastic Search index.'))
        ->addExample('phabricator2', pht('Valid Setting')),
      $this->newOption('search.elastic.version', 'string', null)
        ->setLocked(true)
        ->setDescription(pht('Elastic Version, used for functions that work for a specific elastic version.')),
      $this->newOption('search.elastic.enabled', 'bool', false)
        ->setDescription(pht('Enable the elasticsearch backend.'))
        ->setBoolOptions(array(pht('Enable: Use elasticsearch'),
                               pht('Disable: Use MySQL')))
    );
  }

}
