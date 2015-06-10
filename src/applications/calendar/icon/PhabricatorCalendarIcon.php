<?php

final class PhabricatorCalendarIcon extends Phobject {

  public static function getIconMap() {
    return
      array(
        'fa-calendar' => pht('Default'),
        'fa-glass' => pht('Party'),
        'fa-plane' => pht('Travel'),
        'fa-plus-square' => pht('Health / Appointment'),
        'fa-rocket' => pht('Sabatical / Leave'),
        'fa-home' => pht('Working From Home'),
        'fa-coffee' => pht('Coffee Meeting'),
        'fa-users' => pht('Meeting'),
        'fa-cutlery' => pht('Meal'),
        'fa-institution' => pht('Official Business'),
        'fa-bus' => pht('Field Trip'),
        'fa-microphone' => pht('Conference'),
        'fa-birthday-cake' => pht('The Cake is a lie'),
        'fa-bug' => pht('Bug Triage'),
        'fa-train' => pht('Deployment Train'),
        'fa-code-fork' => pht('Branch'),
        'fa-fire-extinguisher' => pht('SWAT'),
        'fa-exchange' => pht('Pairing Session'),
        'fa-wrench' => pht('Maintenance'),
        'fa-file-text-o' => pht('Report'),
        'fa-history' => pht('Review'),
        'fa-phone' => pht('On-Call'),
        'fa-backward' => pht('Progress'),
        'fa-suitcase' => pht('Serious Business'),
      );
  }

  public static function getLabel($key) {
    $map = self::getIconMap();
    return $map[$key];
  }

  public static function getAPIName($key) {
    return substr($key, 3);
  }

  public static function renderIconForChooser($icon) {
    $calendar_icons = self::getIconMap();

    return phutil_tag(
      'span',
      array(),
      array(
        id(new PHUIIconView())->setIconFont($icon),
        ' ',
        idx($calendar_icons, $icon, pht('Unknown Icon')),
      ));
  }

}
