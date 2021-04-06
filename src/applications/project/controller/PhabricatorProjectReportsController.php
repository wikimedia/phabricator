<?php

final class PhabricatorProjectReportsController
  extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
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

    $period = $request->getStr('period');
    if ($period == 'custom') {
      $period = 'period';
    }

    $completed = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Throughput'))
      ->appendChild(
        pht("Tasks completed this %s: %d",
        $period,
        $project_metrics->getMetric('completed')))
      ->appendChild($break)
      ->appendChild(
        pht('Remaining open tasks: %d',
          $project_metrics->getMetric('open_task_count'))
      )
      ->appendChild($break);


    $overdue = $project_metrics->getMetric('overdue');
    if ($overdue) {
      $overdue_view = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Over-due Tasks'));
      $handles = $viewer->loadHandles(array_keys($overdue));
      foreach($overdue as $phid=>$due_date) {
        $handle = $handles->getHandleIfExists($phid, false);
        if ($handle) {
          $human_date = phabricator_date($due_date, $viewer);
          $link = $handle->renderHovercardLink();
          $overdue_row = id(new AphrontMultiColumnView())
            ->addColumn($link)
            ->addColumn($human_date);
          $overdue_view->appendChild($overdue_row);
        }
      }
      $col1->appendChild($overdue_view);
    }

    $task_age_view = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Age Distribution:'));

    $histogram = $project_metrics->getMetric('histogram');
    if (is_array($histogram)) {
      $total = array_sum($histogram);
      $ages = array_keys($histogram);
      $max_age = max($ages);
      foreach ($histogram as $age => $count) {
        $bar = new PHUISegmentBarView();
        $weeks = ceil($age/7);
        $weeksEnd=$weeks-1;
        $label = pht("%d week(s)", $weeks);
        $href = "/maniphest/?createdStart=$weeks weeks ago&createdEnd=$weeksEnd weeks ago";
        $link = phutil_tag('a', ['href'=>$href], $label);
        $bar
          ->setBigbars(true)
          ->setLabel($link);
        if ($age == $max_age) {
          $bar->setLabel('Older');
        }
        $task_age_view->appendChild($bar);
        $bar->newSegment()
          ->setWidth( $count / $total )
          ->setValue($count)
          ->setColor('blue');
      }
    }
    $col1
      ->appendChild($completed)
      ->appendChild($break);
    $col1
      ->appendChild($task_age_view)
      ->appendChild($break);

    $metrics[] = $row;

    $assignments = $project_metrics->getMetric('tasks_by_owner');
    if ($assignments) {
      arsort($assignments);
      $assigned = array_sum($assignments);
      $count = 0;
      $unassigned = $project_metrics->getMetric('unassigned_count');
      $total = $assigned + $unassigned;

      $handles = $viewer->loadHandles(array_keys($assignments));
      $box = id(new PHUIObjectBoxView());
      if ($assigned < $total) {
        $box->setHeaderText(
          pht('Workload: %d of %d open tasks are assigned to %d people.',
           $assigned, $total, count($assignments)));
      } else {
        $box->setHeaderText(
          pht('Workload: All %d open tasks are assigned to %d people.',
          $total, count($assignments))
        );
      }
      $assignedView = id(new PHUISegmentBarView())
        ->setBigbars(true);
      $box->appendChild($assignedView);
      $assignedView
        ->setLabel(pht('Unassigned'))
        ->newSegment()
        ->setWidth($unassigned / $total)
        ->setColor('grey')
        ->setValue($unassigned);
      $assignment_count = count($assignments);

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
          $assignedView
            ->setLabel($handle->renderHovercardLink())
            ->newSegment()
            ->setWidth($tasks / $total)
            ->setColor('blue')
            ->setValue($tasks);
        }
        if ($tasks < 1 || $count == $assignment_count) {
          $base_uri = PhabricatorEnv::getAnyBaseURI();
          $link = new PhutilURI($base_uri);
          $link->setPath("/maniphest/report/user/");
          $window_date = new DateTime();
          $window_date->setTimestamp(
            $request->getInt('startdate'));

          $link->setQueryParams([
            'project' => $project->getPHID(),
            'window' => $window_date->format('r'),
            'order' => '-total'
          ]);
          $box->appendChild(id(new PHUIBoxView())
            ->addPadding(PHUI::PADDING_MEDIUM)
            ->appendChild(
              phutil_tag(
                'a',
                ['href'=>$link],
                pht('See full report.'))
            ));
          break;
        }
      }
      $col2->appendChild($box);
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
        if ($task_count < 1 || !isset($val['name'])) {
          continue;
        }

        $label = phutil_tag('a', ['href'=>$val['href']], $val['name']);
        $bar = id(new PHUISegmentBarView())
          ->setBigbars(true)
          ->setLabel($label);

        $bar->newSegment()
          ->setWidth($task_count / $total )
          ->setValue($task_count)
          ->setColor("blue");
        $workboard_stats->appendChild($bar);
      }
      $col1->appendChild($workboard_stats);
    }

    return $this->newPage()
      ->setNavigation($nav)
      ->setCrumbs($crumbs)
      ->setTitle([$project->getName(), pht('Reports')])
      ->appendChild($view);
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
            $end->modify('last day of march ' . $year);
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
    $data['period'] = $period->getValue();
    $request->setRequestData($data);

    $date_controls = id(new AphrontMultiColumnView())
      ->setFluidLayout(false);

    $date_controls->addColumn($period);
    $date_controls->addColumn($start_date);
    $date_controls->addColumn($end_date);
    $date_controls->addColumn(
      id(new AphrontFormSubmitControl())
        ->setValue(pht('Update')));

    $form = id(new AphrontFormView())
      ->setViewer($viewer)
      ->appendChild($date_controls);

    return $form;
  }

  public static function humanizeDays($days) {
    if ($days > 30) {
      return pht("%d month(s)", 1+floor($days/30));
    }
    if ($days > 6) {
      return pht("%d week(s)", 1+floor($days/7));
    }
    return pht("%d day(s)", $days);
  }
}
