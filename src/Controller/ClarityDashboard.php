<?php
namespace Drupal\microsoft_clarity\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class ClarityDashboard.
 *
 * @package Drupal\microsoft_clarity\Controller
 */
class ClarityDashboard extends ControllerBase {

  /**
   * Returns a Clarity Dashboard page.
   *
   * @return array
   *   Page content renderable array.
   */
  public function dashboard() {
    $config = \Drupal::config('microsoft_clarity.settings');
    $project = $config->get('project_id');

    $site_name = \Drupal::config('system.site')->get('name');

    return [
        '#markup' => '<iframe width="100%" height="1300px" sandbox=""',
        '#type' => 'html_tag',
        '#tag' => 'iframe',
        '#attributes' => [
          'src' => 'https://clarity.microsoft.com/embed?integration=Druapl&druapl-site=' . $site_name . '&drupal-admin=1&project=' . $project ,
          'title' => 'Microsoft Clarity',
          'frameborder' => 0,
          'allowfullscreen' => TRUE,
          'width' => '100%',
          'height' => 1300,
          'scrolling' => FALSE,
          'allowtransparency' => TRUE,
          'sandbox' => 'allow-modals allow-forms allow-scripts allow-same-origin allow-popups',
        ]
    ];
  }
}
