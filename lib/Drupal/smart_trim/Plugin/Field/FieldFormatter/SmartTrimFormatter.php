<?php

/**
 * @file
 * Contains \Drupal\smart_trim\Plugin\Field\FieldFormatter\SmartTrimFormatter.
 */

namespace Drupal\smart_trim\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'smart_trim' formatter.
 *
 * @FieldFormatter(
 *   id = "smart_trim",
 *   label = @Translation("Smart trimmed"),
 *   field_types = {
 *     "text",
 *     "text_long",
 *     "text_with_summary"
 *   },
 *   settings = {
 *     "trim_length" = "300",
 *     "trim_type" = "chars",
 *     "trim_suffix" = "...",
 *     "more_link" = FALSE,
 *     "more_text" = "Read more",
 *     "summary_handler" = "full",
 *     "trim_options" = ""
 *   }
 * )
 */
class SmartTrimFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, array &$form_state) {
    $element = parent::settingsForm($form, $form_state);

    $settings = $this->getSettings();

    $element['trim_length'] = array(
      '#title' => t('Trim length'),
      '#type' => 'textfield',
      '#size' => 10,
      '#default_value' => $settings['trim_length'],
      '#min' => 0,
      '#required' => TRUE,
    );

    $element['trim_type'] = array(
      '#title' => t('Trim units'),
      '#type' => 'select',
      '#options' => array(
        'chars' => t("Characters"),
        'words' => t("Words"),
      ),
      '#default_value' => $settings['trim_type'],
    );

    $element['trim_suffix'] = array(
      '#title' => t('Suffix'),
      '#type' => 'textfield',
      '#size' => 10,
      '#default_value' => $settings['trim_suffix'],
    );

    $element['more_link'] = array(
      '#title' => t('Display more link?'),
      '#type' => 'select',
      '#options' => array(
        0 => t("No"),
        1 => t("Yes"),
      ),
      '#default_value' => $settings['more_link'],
      '#description' => t('Displays a link to the entity (if one exists)'),
    );

    $element['more_text'] = array(
      '#title' => t('More link text'),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $settings['more_text'],
      '#description' => t('If displaying more link, enter the text for the link.'),
    );

    if ($this->fieldDefinition->getType() == 'text_with_summary'){
      $element['summary_handler'] = array(
        '#title' => t('Summary'),
        '#type' => 'select',
        '#options' => array(
          'full' => t("Use summary if present, and do not trim"),
          'trim' => t("Use summary if present, honor trim settings"),
          'ignore' => t("Do not use summary"),
        ),
        '#default_value' => $settings['summary_handler'],
      );
    }

    $element['trim_options'] = array(
      '#title' => t('Additional options'),
      '#type' => 'checkboxes',
      '#options' => array(
        'text' => t('Strip HTML'),
      ),
      '#default_value' => empty($settings['trim_options']) ? array() : $settings['trim_options'],
    );

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = array();
    $settings = $this->getSettings();

    $trim_string = $settings['trim_length'] . ' ' . (($settings['trim_type'] == 'chars') ? t('characters') : t('words'));
    if (drupal_strlen(trim($settings['trim_suffix']))) $trim_string .= " " . t("with suffix");
    if ($settings['more_link']) $trim_string .= ", " . t("with more link");
    $summary[] = $trim_string;

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items) {

    $element = array();
    $settings = $this->getSettings();
    $entity = $items->getEntity();

    foreach ($items as $delta => $item) {

      if (!empty($settings['summary_handler']) && $settings['summary_handler'] != 'ignore' && !empty($item->summary)) {
        $output = $item->summary_processed;
      }
      else {
        $output = $item->processed;
      }
      $char_delta = drupal_strlen(trim($settings['trim_suffix']));

      // Process additional options (currently only HTML on/off)
      if (!empty($settings['trim_options'])) {
        if (!empty($settings['trim_options']['text'])) {
          // Strip tags
          $output = strip_tags(str_replace('<', ' <', $output));

          // Strip out line breaks
          $output = preg_replace('/\n|\r|\t/m', ' ', $output);

          // Strip out non-breaking spaces
          $output = str_replace('&nbsp;', ' ', $output);
          $output = str_replace("\xc2\xa0", ' ', $output);

          // Strip out extra spaces
          $output = trim(preg_replace('/\s\s+/', ' ', $output));
        }
      }

      // Make the trim, provided we're not showing a full summary
      $shortened = FALSE;
      if ($settings['summary_handler'] != 'full' || empty($item->summary)) {
        if ($settings['trim_type'] == 'words') {
          //only bother with this is we have to
          if ($settings['trim_length'] < str_word_count($output)) {
            //use \s or use PREG_CLASS_UNICODE_WORD_BOUNDARY?
            $words = preg_split('/\s/', $output, NULL, PREG_SPLIT_NO_EMPTY);
            $output2 = implode(" ", array_slice($words, 0,  $settings['trim_length']));
            $output2 = _filter_htmlcorrector($output2);
          }
          //field contained fewer words than we're trimming at, so do nothing
          else {
            $output2 = $output;
          }
        }
        else {
          //See https://api.drupal.org/api/drupal/core!modules!text!text.module/function/text_summary/8
          //text_summary is smart about looking for paragraphs, sentences,
          //etc, not strictly just length. Uses truncate_utf8 as well
          $output2 = text_summary($output, $item->format, $settings['trim_length']);
        }

        //verify if we actually performed any shortening
        if (drupal_strlen($output) != drupal_strlen($output2)) {
          $shortened = TRUE;
        }
        $output = $output2;
      }

      // Only include the extension if the text was truncated
      $extension = '';
      if ($shortened) {
        $extension = $settings['trim_suffix'];
      }
      // Don't duplicate period at end of text and beginning of extension
      if (substr($output, -1, 1) == '.' && substr($extension, 0, 1) == '.') {
        $extension = substr($extension, 1);
      }
      // Add the link, if there is one!
      $uri = $entity->uri();
      // But wait! Don't add a more link if the field ends in <!--break-->
      if ($uri && $settings['more_link'] && strpos(strrev($output), strrev('<!--break-->')) !== 0) {
        $extension .= l(t($settings['more_text']), $uri['path'], array('html' => TRUE, 'attributes' => array('class' => array('more-link'))));
      }

      $output_appended = preg_replace('#^(.*)(\s?)(</[^>]+>)$#Us', '$1' . $extension . '$3', $output);

      //check if the regex did anything. if not, append manually
      if ($output_appended == $output) $output_appended = $output . $extension;
      $element[$delta] = array('#markup' => $output_appended);

    }

    return $element;
  }
}
