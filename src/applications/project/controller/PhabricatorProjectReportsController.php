<?php

final class PhabricatorProjectReportsController
  extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
  }
  public static function humanizeDays($days) {
    if ($days > 30) {
      return pht("%d month(s)", 1+floor($days/30));
    }
    if ($days > 6) {
      return pht("%d week(s)", 1+floor($days/7));
    }
    return pht("%d day(s)", 1+floor($days));
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();

    $response = $this->loadProject();
    if ($response) {
      return $response;
    }

    $project = $this->getProject();
    $id = $project->getID();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_EDIT);

    $nav = $this->newNavigation(
      $project,
      PhabricatorProject::ITEM_REPORTS);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Reports'));
    $crumbs->setBorder(true);

    $chart_panel = id(new PhabricatorProjectBurndownChartEngine())
      ->setViewer($viewer)
      ->setProjects(array($project))
      ->buildChartPanel();

    $chart_panel->setName(pht('%s: Burndown', $project->getName()));

    $chart_view = id(new PhabricatorDashboardPanelRenderingEngine())
      ->setViewer($viewer)
      ->setPanel($chart_panel)
      ->setParentPanelPHIDs(array())
      ->renderPanel();

    $activity_panel = id(new PhabricatorProjectActivityChartEngine())
      ->setViewer($viewer)
      ->setProjects(array($project))
      ->buildChartPanel();

    $activity_panel->setName(pht('%s: Activity', $project->getName()));

    $activity_view = id(new PhabricatorDashboardPanelRenderingEngine())
      ->setViewer($viewer)
      ->setPanel($activity_panel)
      ->setParentPanelPHIDs(array())
      ->renderPanel();

    $metrics = [];
    $metrics[] = $this->renderDateControls($request);

    $project_metrics = new ProjectMetrics($request, $project);
    $project_metrics->computeMetrics();

    $row = id(new AphrontMultiColumnView())
        ->setFluidLayout(true);

    $col1 = new PHUIObjectBoxView();
    $row->addColumn($col1);
    $col2 = new PHUIObjectBoxView();
    $row->addColumn($col2);
    $break = [phutil_tag('br')];
    $completed = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Throughput'))
      ->appendChild(pht("%d tasks completed.",
      $project_metrics->getMetric('completed')))
      ->appendChild($break);

    $age = $project_metrics->getMetric('age');
    $task_age_view = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Age of open tasks'));


    $histogram = $project_metrics->getMetric('histogram');
    $total = array_sum ($histogram);
    foreach ($histogram as $age => $count) {
      $bar = new PHUISegmentBarView();
      $bar->setBigbars(true)
        ->setLabel(pht("%d week(s)", $age/7));
      $task_age_view->appendChild($bar);
      $bar->newSegment()
        ->setWidth( $count / $total )
        ->setValue($count)
        ->setColor('blue');
    }
    $col1->appendChild($completed)->appendChild($break);
    $col1->appendChild($task_age_view)->appendChild($break);
    $metrics[] = $row;


    $assignments = $project_metrics->getMetric('tasks_by_owner');
    arsort($assignments);

    $handles = $viewer->loadHandles(array_keys($assignments));
    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Assigned Workload'));

    //    $row->addColumn($box);
    $col1->appendChild($box);

    $total = array_sum($assignments);
    $count=0;
    foreach ($assignments as $phid=>$tasks) {
      if (empty($phid)) {
        continue;
      }

      $handle = $handles->getHandleIfExists($phid, false);
      if ($handle) {
        $count++;
        $assignedView = id(new PHUISegmentBarView())
          ->setBigbars(true);
        $box->appendChild($assignedView);
        $assignedView->setLabel(
          $handle->renderHovercardLink())
            ->newSegment()
            ->setWidth($tasks / $total)
            ->setColor('blue')
            ->setValue($tasks);
      }
      if ($tasks < 2 || $count > 8) {
        break;
      }
    }


    $view = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_MEDIUM)
      ->appendChild([
        $metrics,
        $chart_view,
        $activity_view,
      ]);

    if ($project->getHasWorkboard()) {
      $columns = $project_metrics->getMetric('columns');

      $workboard_stats =   id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Open Tasks by column'));

      $col_count = count($columns);
      $total = 0;
      foreach ($columns as $col=>$val) {
        $total += count($val['tasks']);
      }
      $avg_per_column = $total / $col_count;
      $max_age = $project_metrics->getMetric('max_age');
      foreach ($columns as $col=>$val) {
        $task_count = count($val['tasks']);
        if ($task_count < 1) {
          continue;
        }
        if ($task_count) {
          $bar = id(new PHUISegmentBarView())
          ->setBigbars(true)
          ->setLabel($val['name']);

          $bar->newSegment()
            ->setWidth($task_count / $total )
            ->setValue($task_count)
            ->setColor("blue");
          $workboard_stats->appendChild($bar);
        }

      }

      $col2->appendChild($workboard_stats);
    }

    return $this->newPage()
      ->setNavigation($nav)
      ->setCrumbs($crumbs)
      ->setTitle(array($project->getName(), pht('Reports')))
      ->appendChild($view);
  }

  private function renderStatsChart($stats) {
    return '=-[ '. $stats['min'].' [' . $stats['mean'] . '] ' . $stats['max'] . ' ]-=';
  }

  private function renderDateControls(AphrontRequest $request) {
    $viewer = $request->getViewer();

    $periods = [
      'week'    => pht('Week'),
      'month'   => pht('Month'),
      'quarter'  => pht('Quarter'),
      'custom'     => pht('Custom')
    ];

    $period = id(new AphrontFormSelectControl())
    ->setName('period')
    ->setLabel(pht('Period'))
    ->setOptions($periods);
    $period->readValueFromRequest($request);

    if (!array_key_exists($period->getValue(), $periods)) {
      $period->setValue('week');
    }

    $start = new DateTime('now');
    $start_date = id(new AphrontFormDateControl())
    ->setLabel(pht("From"))
    ->setName("startdate")
    ->setUser($viewer)
    ->setIsTimeDisabled(true)
    ->setIsDisabled(false)
    ->setAllowNull(false);
    $start_date->readValueFromRequest($request);
    if ($start_date->getValue() == null) {
      $start_date->setValue($start->getTimestamp());
    } else {
      $start->setTimestamp($start_date->getValue());
    }

    $end_date = id(new AphrontFormDateControl())
    ->setLabel(pht("To"))
    ->setName("enddate")
    ->setUser($viewer)
    ->setIsTimeDisabled(true)
    ->setIsDisabled(false)
    ->setAllowNull(false);
    $end_date->readValueFromRequest($request);
    $end = new DateTime('now');
    $end->setTimestamp($end_date->getValue());

    $period_val = $period->getValue();

    if ($period_val != "custom") {
      if ($period_val == 'week') {
        $dayofweek = $start->format("N");
        if ($dayofweek == 7) {
          $start->modify('tomorrow');
        } else {
          $start->modify('monday this week');
        }
        $end->setTimestamp($start->getTimestamp());
        $end->modify('next sunday');
      } else if ($period_val == 'month') {
        $start->modify('first day of this month');
        $end->setTimestamp($start->getTimestamp());
        $end->modify('last day of this month');
      } else if ($period_val == 'quarter') {
        $month = $start->format('n');
        $year = $start->format('Y');
        if ($month < 4) {
            $start->modify('first day of january ' . $year);
            $end->modify('first day of january ' . $year);
        } else if ($month < 7) {
            $start->modify('first day of april ' . $year);
            $end->modify('last day of june ' . $year);
        } elseif ($month < 10) {
            $start->modify('first day of july ' . $year);
            $end->modify('last day of september ' . $year);
        } else {
            $start->modify('first day of october ' . $year);
            $end->modify('last day of december ' . $year);
        }
      }
      $start_date->setValue($start->getTimestamp());
      $end_date->setValue($end->getTimestamp());
    }

    $data = $request->getRequestData();
    $data['startdate'] = $start_date->getValue();
    $data['enddate'] = $end_date->getValue();
    $request->setRequestData($data);

    $date_controls = id(new AphrontMultiColumnView())
      ->setFluidLayout(false);

    $date_controls->addColumn($period);
    $date_controls->addColumn($start_date);
    $date_controls->addColumn($end_date);
    $date_controls->addColumn(id(new AphrontFormSubmitControl())
    ->setValue(pht('Update')));

    $form = id(new AphrontFormView())
      ->setViewer($viewer)
      ->appendChild($date_controls);

    return $form;
  }

}
