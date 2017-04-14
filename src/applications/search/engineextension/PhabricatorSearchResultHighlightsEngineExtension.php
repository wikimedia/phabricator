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
      $parts = explode('||SPLIT||', $highlights);
      $highlights = array();
      foreach ($parts as $part) {
        if ($part == '##STARTHIGHLIGHT##') {
          $part = phutil_safe_html('<strong>');
        } else if ($part == '##ENDHIGHLIGHT##') {
          $part = phutil_safe_html('</strong>');
        } else {
          $part = phutil_escape_html($part);
        }
        $highlights[] = $part;
      }

      $highlight_view = phutil_tag_div('phui-oi-subhead',
        array(
          phutil_tag('span', array(
            'class'=>'visual-only phui-icon-view phui-font-fa fa-quote-left')
          ),
          ' ',
          $highlights,
          ' ... '
        )
      );
      $item->appendChild($highlight_view);
    }

    return $item;
  }

}
