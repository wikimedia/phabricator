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
    $status_map = PhabricatorSearchCluster::getConnectionStatusMap();
    $rows = array();
    foreach ($services as $service) {
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
        } catch (Exception $ex) {
          $status['icon'] = 'fa-times';
          $status['label'] = pht('Connection Error');
          $status['color'] = 'red';
        }

        $type_icon = 'fa-search sky';
        $type_tip = $host->getDisplayName();

        $type_icon = id(new PHUIIconView())
          ->setIcon($type_icon);
        $status_view = array(
          id(new PHUIIconView())->setIcon($status['icon'].' '.$status['color']),
          ' ',
          $status['label'],
        );

        $roles = implode(', ', array_keys($host->getRoles()));
        $rows[] = array(
          array($type_icon, ' ', $type_tip),
          $host->getProtocol(),
          $host->getHost(),
          $host->getPort(),
          $status_view,
          $roles,
        );
      }
    }

    $table = id(new AphrontTableView($rows))
      ->setNoDataString(
        pht('No search servers are configured.'))
      ->setHeaders(
        array(
          pht('Type'),
          pht('Protocol'),
          pht('Host'),
          pht('Port'),
          pht('Status'),
          pht('Roles'),
          null,
        ))
      ->setColumnClasses(
        array(
          null,
          null,
          null,
          null,
          null,
          null,
          'wide',
        ));

    return $table;
  }
}
