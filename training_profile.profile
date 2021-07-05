<?php

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form().
  */
function training_profile_form_install_configure_form_alter(&$form, FormStateInterface $form_state) {
  $form['site_information']['site_name']['#default_value'] = 'Pokemon for Drupal';
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


