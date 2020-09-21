<?php

final class ManiphestTaskSubtypeHeraldField
  extends ManiphestTaskHeraldField {

  const FIELDCONST = 'maniphest.task.subtype';

  public function getHeraldFieldName() {
    return pht('Type');
  }

  public function getHeraldFieldValue($object) {
    return $object->getSubtype();
  }

  protected function getHeraldFieldStandardType() {
    return self::STANDARD_PHID;
  }

  protected function getDatasource() {
    return id(new ManiphestTaskSubtypeDatasource())
      ->setLimit(1);
  }

  protected function getDatasourceValueMap() {
    $map = id(new ManiphestTask())->newEditEngineSubtypeMap();
    return $map->getSubtypes();
  }

}
