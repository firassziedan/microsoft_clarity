<?php

namespace Drupal\microsoft_clarity\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\microsoft_clarity\JavascriptLocalCache;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure microsoft_clarity settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The microsoft_clarity local javascript cache manager.
   *
   * @var \Drupal\microsoft_clarity\JavascriptLocalCache
   */
  protected JavascriptLocalCache $clarityJavascript;

  /**
   * The constructor method.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\microsoft_clarity\JavascriptLocalCache $clarity_javascript
   *   The JS Local Cache service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, JavascriptLocalCache $clarity_javascript) {
    parent::__construct($config_factory);
    $this->clarityJavascript = $clarity_javascript;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('microsoft_clarity.javascript_cache')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'microsoft_clarity_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('microsoft_clarity.settings');

    $form['project_id'] = [
      '#title' => $this->t('Project ID'),
      '#type' => 'textfield',
      '#default_value' => $config->get('project_id'),
    ];

    // Visibility settings.
    $form['tracking_scope'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Tracking scope'),
    ];

    // Page specific visibility configurations.
    $form['tracking']['page_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Pages'),
      '#group' => 'tracking_scope',
    ];
    $form['tracking']['page_visibility_settings']['visibility_request_path_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking to specific pages'),
      '#options' => [
        0 => $this->t('Every page except the listed pages'),
        1 => $this->t('The listed pages only'),
      ],
      '#default_value' => $config->get('visibility.request_path_mode'),
    ];
    $visibility_request_path_pages = $config->get('visibility.request_path_pages');
    $form['tracking']['page_visibility_settings']['visibility_request_path_pages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pages'),
      '#title_display' => 'invisible',
      '#default_value' => !empty($visibility_request_path_pages) ? $visibility_request_path_pages : '',
      '#description' => $this->t("Specify pages by using their paths. Enter one path per line. The '*' character is a wildcard. Example paths are %blog for the blog page and %blog-wildcard for every personal blog. %front is the front page.",
        [
          '%blog' => '/blog',
          '%blog-wildcard' => '/blog/*',
          '%front' => '<front>',
        ]
      ),
      '#rows' => 10,
    ];

    // Render the role overview.
    $form['tracking']['role_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Roles'),
      '#group' => 'tracking_scope',
    ];
    $form['tracking']['role_visibility_settings']['visibility_user_role_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking for specific roles'),
      '#options' => [
        0 => $this->t('Add to the selected roles only'),
        1 => $this->t('Add to every role except the selected ones'),
      ],
      '#default_value' => $config->get('visibility.user_role_mode'),
    ];
    $visibility_user_role_roles = $config->get('visibility.user_role_roles');
    $form['tracking']['role_visibility_settings']['visibility_user_role_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#default_value' => !empty($visibility_user_role_roles) ? $visibility_user_role_roles : [],
      '#options' => array_map('\Drupal\Component\Utility\Html::escape', user_role_names()),
      '#description' => $this->t('If none of the roles are selected, all users will be tracked. If a user has any of the roles checked, that user will be tracked (or excluded, depending on the setting above).'),
    ];

    // Advanced feature configurations.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['local_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Locally cache tracking code file'),
      '#description' => $this->t("If checked, the tracking code file is retrieved from Clarity server and cached locally. It is updated daily from Clarity's servers to ensure updates to tracking code are reflected in the local copy. Do not activate this until after Clarity service has confirmed that site tracking is working!"),
      '#default_value' => $config->get('local_cache'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Trim some text values.
    $form_state->setValue('visibility_request_path_pages', trim($form_state->getValue('visibility_request_path_pages')));
    $form_state->setValue('domains', trim($form_state->getValue('domains')));

    $form_state->setValue('visibility_user_role_roles', array_filter($form_state->getValue('visibility_user_role_roles') ?? []));

    // Verify that every path is prefixed with a slash, but don't check PHP
    // code snippets and do not check for slashes if no paths configured.
    if ($form_state->getValue('visibility_request_path_mode') != 2 && !empty($form_state->getValue('visibility_request_path_pages'))) {
      $pages = preg_split('/(\r\n?|\n)/', $form_state->getValue('visibility_request_path_pages'));
      foreach ($pages as $page) {
        if (!str_starts_with($page, '/') && $page !== '<front>') {
          $form_state->setErrorByName('visibility_request_path_pages', $this->t('Path "@page" not prefixed with slash.', ['@page' => $page]));
          // Drupal forms show one error only.
          break;
        }
      }
    }

    // Clear obsolete local cache if cache has been disabled.
    if ($form_state->isValueEmpty('local_cache') && $form['advanced']['local_cache']['#default_value']) {
      $this->clarityJavascript->clearJsCache();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('microsoft_clarity.settings');

    $config
      ->set('project_id', $form_state->getValue('project_id'))
      ->set('visibility.request_path_mode', $form_state->getValue('visibility_request_path_mode'))
      ->set('visibility.request_path_pages', $form_state->getValue('visibility_request_path_pages'))
      ->set('visibility.user_role_mode', $form_state->getValue('visibility_user_role_mode'))
      ->set('visibility.user_role_roles', $form_state->getValue('visibility_user_role_roles'))
      ->set('local_cache', $form_state->getValue('local_cache'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['microsoft_clarity.settings'];
  }

}
