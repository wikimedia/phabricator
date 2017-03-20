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

    $services = PhabricatorSearchService::getAllServices();
    Javelin::initBehavior('phabricator-tooltips');

    $view = array();
    foreach ($services as $service) {
      $view[] = $this->renderStatusView($service);
    }
    return $view;
  }

  private function renderStatusView($service) {
    $head = array_merge(
        array(pht('Type')),
        array_keys($service->getStatusViewColumns()),
        array(pht('Status')));

    $rows = array();

    $status_map = PhabricatorSearchService::getConnectionStatusMap();
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
    if ($stats !== false) {
      foreach ($stats as $label => $val) {
        $view->addProperty($label, $val);
      }
    }
    return $view;
  }

}
