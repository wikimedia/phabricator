<?php

final class DiffusionPushEventGarbageCollector
  extends PhabricatorGarbageCollector {

  const COLLECTORCONST = 'diffusion.push';

  public function getCollectorName() {
    return pht('Repository Push Logs');
  }

  public function getDefaultRetentionPolicy() {
    return phutil_units('60 days in seconds');
  }

  protected function collectGarbage() {

    $table = new PhabricatorRepositoryPushEvent();
    $conn_w = $table->establishConnection('w');

    // find phids of old Push Events that are beyond the retention policy.
    $phids_to_delete = queryfx_all(
      $conn_w,
      'SELECT PHID FROM %T WHERE epoch < %d LIMIT 100',
      $table->getTableName(),
      $this->getGarbageEpoch());

    if ($phids_to_delete) {
      $log_table = new PhabricatorRepositoryPushLog();
      $log_conn_w = $log_table->establishConnection('w');

      // Delete any related push log records.
      queryfx(
        $log_conn_w,
        'DELETE FROM %T where pushEventPHID IN (%Ls)',
        $log_table->getTableName(),
        $phids_to_delete);

      // Finally, delete the push events
      queryfx(
        $conn_w,
        'DELETE FROM %T WHERE PHID IN (%Ls) LIMIT 100',
        $table->getTableName(),
        $phids_to_delete);
    }
    return ($conn_w->getAffectedRows() == 100);
  }

}
