<?php
use CRM_Beucmeps2024_ExtensionUtil as E;

function _civicrm_api3_meps2024_Import_spec(&$spec) {
  $spec['file'] = [
    'title' => 'File',
    'api.required' => 1,
  ];

}

function civicrm_api3_meps2024_Import($params) {
  try {
    $imp = new CRM_Beucmeps2024_Import();
    $msg = $imp->run($params['file']);

    return civicrm_api3_create_success($msg, $params, 'Meps2024', 'Import');
  }
  catch (Exception $e) {
    throw new API_Exception($e->getMessage(), 999);
  }
}
