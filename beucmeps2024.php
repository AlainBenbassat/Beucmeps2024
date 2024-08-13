<?php

require_once 'beucmeps2024.civix.php';

use CRM_Beucmeps2024_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function beucmeps2024_civicrm_config(&$config): void {
  _beucmeps2024_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function beucmeps2024_civicrm_install(): void {
  _beucmeps2024_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function beucmeps2024_civicrm_enable(): void {
  _beucmeps2024_civix_civicrm_enable();
}
