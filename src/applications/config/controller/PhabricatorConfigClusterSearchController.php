<?php

final class PhabricatorConfigClusterSearchController
  extends PhabricatorConfigController {

  public function handleRequest(AphrontRequest $request) {
    $nav = $this->buildSideNavView();
    $nav->selectFilter('cluster/search/');

    $title = pht('Cluster Search');
    $doc_href = PhabricatorEnv::getDoclink('Cluster: Search');

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setProfileHeader(true)
      ->addActionLink(
        id(new PHUIButtonView())
          ->setIcon('fa-book')
          ->setHref($doc_href)
          ->setTag('a')
          ->setText(pht('Documentation')));

    $crumbs = $this
      ->buildApplicationCrumbs($nav)
      ->addTextCrumb($title)
      ->setBorder(true);

    $search_status = $this->buildClusterSearchStatus();

    $content = id(new PhabricatorConfigPageView())
      ->setHeader($header)
      ->setContent($search_status);

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->setNavigation($nav)
      ->appendChild($content)
      ->addClass('white-background');
  }

  private function buildClusterSearchStatus() {
    $viewer = $this->getViewer();

    $services = PhabricatorSearchCluster::getAllServices();
    Javelin::initBehavior('phabricator-tooltips');

    $view = array();
    foreach ($services as $service) {
      $view[] = $this->renderStatusView($service);
    }
    return $view;
  }

  private function renderStatusView($service) {
    $rows = array();

    $head = array_merge(
        array(pht('Type')),
        array_keys($service->getStatusViewColumns()),
        array(pht('Status')));

    $status_map = PhabricatorSearchCluster::getConnectionStatusMap();
    foreach ($service->getHosts() as $host) {
      $reachable = false;
      try {
        $engine = $host->getEngine();
        $reachable = $engine->indexExists();
      } catch (Exception $ex) {
        $reachable = false;
      }
      $host->didHealthCheck($reachable);
      try {
        $status = $host->getConnectionStatus();
        $status = idx($status_map, $status, array());
        $stats = $engine->getIndexStats();
      } catch (Exception $ex) {
        $status['icon'] = 'fa-times';
        $status['label'] = pht('Connection Error');
        $status['color'] = 'red';
        $stats = array();
      }
      $stats_view = $this->renderIndexStats($stats);
      $type_icon = 'fa-search sky';
      $type_tip = $host->getDisplayName();

      $type_icon = id(new PHUIIconView())
        ->setIcon($type_icon);
      $status_view = array(
        id(new PHUIIconView())->setIcon($status['icon'].' '.$status['color']),
        ' ',
        $status['label'],
      );
      $row = array(array($type_icon, ' ', $type_tip));
      $row = array_merge($row, array_values(
        $host->getStatusViewColumns()));
      $row[] = $status_view;
      $rows[] = $row;
    }

    $table = id(new AphrontTableView($rows))
    ->setNoDataString(pht('No search servers are configured.'))
    ->setHeaders($head);

    return id(new PHUIObjectBoxView())
      ->setHeaderText($service->getDisplayName())
      ->addPropertyList($stats_view)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setTable($table);
  }

  private function renderIndexStats($stats) {
    $view = id(new PHUIPropertyListView());
    if ($stats == false) {
      $view->addProperty(pht('Stats'), $this->renderNo(pht('N/A')));
      return $view;
    }
    $view->addProperty(pht('Queries'),
      $stats['total']['search']['query_total']);
    $view->addProperty(pht('Documents'),
      $stats['total']['docs']['count']);
    $view->addProperty(pht('Deleted'),
      $stats['total']['docs']['deleted']);
    $view->addProperty(pht('Storage Used'),
      phutil_format_bytes($stats['total']['store']['size_in_bytes']));

    return $view;
  }

  private function renderYes($info) {
    return array(
      id(new PHUIIconView())->setIcon('fa-check', 'green'),
      ' ',
      $info,
    );
  }

  private function renderNo($info) {
    return array(
      id(new PHUIIconView())->setIcon('fa-times-circle', 'red'),
      ' ',
      $info,
    );
  }

  private function renderInfo($info) {
    return array(
      id(new PHUIIconView())->setIcon('fa-info-circle', 'grey'),
      ' ',
      $info,
    );
  }

}
