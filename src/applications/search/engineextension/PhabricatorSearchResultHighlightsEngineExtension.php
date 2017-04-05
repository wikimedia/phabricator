<?php

class PhabricatorSearchResultHighlightsEngineExtension
  extends PhabricatorSearchResultEngineExtension {

  const EXTENSIONKEY = 'highlights';

  public function isExtensionEnabled() {
    return true;
  }

  public function getExtensionName() {
    return 'SearchResultHighlights';
  }

  public function canRenderItemView(PhabricatorFulltextResult $result) {
    return true;
  }

  public function renderItemView(
    PHUIObjectItemView $item,
    PhabricatorFulltextResult $result) {

    $highlights = $result->getHighlights('body');
    if ($highlights) {
      $highlight_view = phutil_tag_div('phui-oi-subhead',
        array(
          phutil_tag('span', array(
            'class'=>'visual-only phui-icon-view phui-font-fa fa-quote-left')
          ),
          ' ',
          phutil_safe_html($highlights),
          ' ... '
        )
      );
      $item->appendChild($highlight_view);

    }

    return $item;
  }

}
