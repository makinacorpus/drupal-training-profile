<?php

declare(strict_types=1);

/**
 * @file
 * Training profile feature file.
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Implements hook_help().
 */
function training_profile_help($route_name, RouteMatchInterface $route_match) {
  switch ($route_name) {
    case 'help.page.training_profile':
      return '<pre>' . \file_get_contents(\dirname(__FILE__) . '/README.md') . '</pre>';
  }
}

function training_profile_preprocess_status_report_general_info(&$variables) {
  $porfileInfos =  install_profile_info('training_profile');
  $variables['drupal']['value'] = [
    '#markup' => \sprintf(
      <<<HTML
        <h4 class="system-status-general-info__sub-item-title">Version du coeur</h4>
        %s
        <h4 class="system-status-general-info__sub-item-title">Version du profil d'installation</h4>
        %s
      HTML,
      $variables['drupal']['value'],
      $porfileInfos['version']
    )
  ];
}

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form().
 */
function training_profile_form_install_configure_form_alter(&$form, FormStateInterface $form_state) {
  $form['site_information']['site_name']['#default_value'] = 'Pokemon';
  $form['site_information']['site_mail']['#default_value'] = 'contact@pokemon.local';
  $form['#submit'][] = 'training_profile_form_install_configure_submit';
}

/**
 * Submission handler to sync the contact.form.feedback recipient.
 */
function training_profile_form_install_configure_submit($form, FormStateInterface $form_state) {
  $site_mail = $form_state->getValue('site_mail');
  \Drupal::entityTypeManager()
    ->getStorage('contact_form')
    ->load('feedback')
    ->setRecipients([$site_mail])
    ->trustData()
    ->save();
}
