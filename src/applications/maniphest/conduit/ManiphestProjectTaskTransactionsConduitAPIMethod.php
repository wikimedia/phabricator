<?php

final class ManiphestProjectTaskTransactionsConduitAPIMethod
extends ManiphestConduitAPIMethod {

  public function getAPIMethodName() {
    return 'maniphest.project.task.transactions';
  }

  public function getMethodDescription() {
    return pht('Retrieve Maniphest task transactions for tasks that are now
     or were previously in a given project.');
  }

  protected function defineParamTypes() {
    return array(
      'project' => 'required project',
      'task_ids' => 'optional list<task>',
    );
  }

  protected function defineReturnType() {
    return 'nonempty list<dict<string, wild>>';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodStatusDescription() {
    return pht('This method is new and unstable.');
  }

  protected function execute(ConduitAPIRequest $request) {
    $results = array();

    $project = $request->getValue('project');
    if ($project[0] == '#') {
      $project = substr($project, 1);
    }
    $projects = [$project];
    $resolved = id(new PhabricatorProjectPHIDResolver())
      ->setViewer($this->getViewer())
      ->resolvePHIDs($projects);
    $project_phid = $resolved[0];

    if (substr($project_phid, 0, 9) !== 'PHID-PROJ') {
      throw id(new ConduitException('ERR-INVALID-PARAMETER'))
      ->setErrorDescription(pht('project must be a valid phid or hashtag.'));
    }


    $storage = new ManiphestTransaction();
    $conn = $storage->establishConnection('r');
    $rows = queryfx_all($conn,
       'SELECT DISTINCT objectPHID AS task FROM %T
        WHERE
            newValue like %~
          OR oldValue like %~
          OR metadata like %~
    ',
    $storage->getTableName(),
    $project_phid,
    $project_phid,
    $project_phid);

    $task_phids = [];
    foreach ($rows as $row) {
      $task_phids[] = $row['task'];
    }


    $task_ids = $request->getValue('task_ids');


    $query = new ManiphestTaskQuery();
    $query
      ->setViewer($request->getUser())
      ->withPHIDs($task_phids);

    if ($task_ids) {
      $task_ids_numeric = [];
      foreach ($task_ids as $task_id) {
        if ($task_id[0] == 'T') {
          $task_id = substr($task_id, 1);
        }
        $task_id = (int)$task_id;
        if ($task_id > 0) {
          $task_ids_numeric[] = $task_id;
        }
      }
      if (count($task_ids_numeric)) {
        $query->withIDs($task_ids_numeric);
      }
    }

    $tasks = $query->execute();
    $tasks = mpull($tasks, null, 'getPHID');

    $transactions = array();
    if ($tasks) {
      $transactions = id(new ManiphestTransactionQuery())
        ->setViewer($request->getUser())
        ->withObjectPHIDs(mpull($tasks, 'getPHID'))
        ->needComments(true)
        ->execute();
    }

    foreach ($transactions as $transaction) {
      $task_phid = $transaction->getObjectPHID();
      if (empty($tasks[$task_phid])) {
        continue;
      }

      $task_id = $tasks[$task_phid]->getID();

      $comments = null;
      if ($transaction->hasComment()) {
        $comments = $transaction->getComment()->getContent();
      }

      $results[$task_id][] = array(
        'taskID'          => $task_id,
        'action'          => $transaction->getActionName(),
        'title'           => (string)$transaction->getTitle(),
        'transactionID'   => $transaction->getID(),
        'transactionPHID' => $transaction->getPHID(),
        'transactionType' => $transaction->getTransactionType(),
        'oldValue'        => $transaction->getOldValue(),
        'newValue'        => $transaction->getNewValue(),
        'meta'            => $transaction->getMetadata(),
        'comments'        => $comments,
        'authorPHID'      => $transaction->getAuthorPHID(),
        'dateCreated'     => $transaction->getDateCreated(),
      );
    }

    return $results;
  }
}
