<?php

class CRM_Beucmeps2024_Import {
  private $colIndexes = [];
  private const EP_CONTACT_ID = 326;
  private const REL_TYPE_ID_EMPLOYER = 5;
  private const REL_TYPE_ID_MEMBER = 48;

  public function run($file) {
    $this->validateHeader();
    $this->assertFileExists($file);
    $f = $this->openFile($file);

    $this->disableOldCommittees();

    $numCreated = 0;
    $numUpdated = 0;
    $i = 0;
    while (($data = fgetcsv($f, 10000, ",")) !== FALSE) {
      $data = array_map( "CRM_Beucmeps2024_Import::convert", $data );
      $i++;

      echo "Line $i...\n";
      $stopAtLine = 0;
      if ($i == 1 || $i < $stopAtLine) {
        continue;
      }
      else {
        $ret = $this->importLine($i, $data);
        if ($ret == 'created') {
          $numCreated++;
        }
        else {
          $numUpdated++;
        }
      }
    }

    return "Created MEPS: $numCreated, Updated MEPS: $numUpdated";
  }

  private function assertFileExists($file) {
    if (!file_exists($file)) {
      throw new Exception("$file does not exist");
    }
  }

  private function openFile($file) {
    $f = fopen($file, "r");
    if ($f === FALSE) {
      throw new Exception("Cannot open $file");
    }

    return $f;
  }

  public function importLine(int $lineNumber, array $data): bool {
    $status = 'updated';

    $contactId = $this->existsContact($data);
    if ($contactId === FALSE) {
      $contactId = $this->createContact($data);
      $status = 'created';
    }

    $this->updateContact($contactId, $data);

    return $status;
  }

  private function existsContact($data) {
    $this->debug(__METHOD__ . ': ' . $data[$this->colIndexes['first_name']] . ' ' . $data[$this->colIndexes['last_name']]);

    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('*')
      ->addWhere('first_name', '=', $data[$this->colIndexes['first_name']])
      ->addWhere('last_name', '=', $data[$this->colIndexes['last_name']])
      ->addWhere('employer_id', '=', self::EP_CONTACT_ID)
      ->execute()
      ->first();

    if ($contact) {
      return $contact['id'];
    }
    else {
      return FALSE;
    }
  }

  private function createContact($data) {
    $this->debug(__METHOD__);

    $results = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', $data[$this->colIndexes['first_name']])
      ->addValue('last_name', $data[$this->colIndexes['last_name']])
      ->execute();

    $contactId = $results[0]['id'];
    $this->createRelationShip($contactId, self::EP_CONTACT_ID, self::REL_TYPE_ID_EMPLOYER);
    return $contactId;
  }

  private function createRelationShip($sourceId, $targetId, $relTypeId, $startDate = NULL) {
    $this->debug(__METHOD__);

    $relationship = \Civi\Api4\Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $sourceId)
      ->addWhere('contact_id_b', '=', $targetId)
      ->addWhere('relationship_type_id', '=', $relTypeId)
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->first();

    if ($relationship) {
      return; // OK
    }

    \Civi\Api4\Relationship::create(FALSE)
      ->addValue('contact_id_a', $sourceId)
      ->addValue('contact_id_b', $targetId)
      ->addValue('relationship_type_id', $relTypeId)
      ->addValue('is_active', TRUE)
      ->addValue('start_date', $startDate)
      ->execute();
  }

  private function updateContact($contactId, $data) {
    $this->debug(__METHOD__);

    $this->updateBasicFields($contactId, $data);
    $this->updateGroup($contactId, $data[$this->colIndexes['political_group']]);
    $this->updateGroup($contactId, $data[$this->colIndexes['country']]);
    $this->updatePhone($contactId, $data[$this->colIndexes['phone']]);
    $this->updateEmail($contactId, $data[$this->colIndexes['email']]);
    $this->updateWebsite($contactId, 'Work', $data[$this->colIndexes['mep_homepage']]);
    $this->updateWebsite($contactId, 'MEP', 'https://www.europarl.europa.eu/meps/en/' . $data[$this->colIndexes['id']]);
    $this->updateCommittee($contactId, $data[$this->colIndexes['committee']]);
  }

  private function updateBasicFields($contactId, $data) {
    $this->debug(__METHOD__);

    $apiCall = \Civi\Api4\Contact::update(FALSE)
      ->addValue('prefix_id:label', $data[$this->colIndexes['prefix']] . '.')
      ->addValue('job_title', 'MEP')
      ->addValue('employer_id', self::EP_CONTACT_ID)
      ->addValue('source', 'import August 2024')
      ->addWhere('id', '=', $contactId);

    if ($data[$this->colIndexes['mep_gender']] == 'male') {
      $apiCall = $apiCall->addValue('gender_id', 2);
    }

    if ($data[$this->colIndexes['mep_gender']] == 'female') {
      $apiCall = $apiCall->addValue('gender_id', 1);
    }

    if ($data[$this->colIndexes['mep_image']]) {
      $apiCall = $apiCall->addValue('image_URL', $data[$this->colIndexes['mep_image']]);
    }

    $apiCall->execute();
  }

  private function updateGroup($contactId, $groupName) {
    $this->debug(__METHOD__);

    $groupContactId = $this->isInGroup($contactId, $groupName);
    if ($groupContactId) {
      \Civi\Api4\GroupContact::update(FALSE)
        ->addValue('status', 'Added')
        ->addWhere('id', '=', $groupContactId)
        ->execute();
    }
    else {
      $groupContact = \Civi\Api4\GroupContact::create(FALSE)
        ->addValue('group_id:label', $groupName)
        ->addValue('contact_id', $contactId)
        ->addValue('status', 'Added')
        ->execute();
    }
  }

  private function isInGroup($contactId, $groupName) {
    $groupContact = \Civi\Api4\GroupContact::get(FALSE)
      ->addWhere('group_id:label', '=', $groupName)
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->first();

    if ($groupContact) {
      return $groupContact['id'];
    }
    else {
      return FALSE;
    }
  }

  private function updatePhone($contactId, $phone) {
    $this->debug(__METHOD__);

    if (empty($phone)) {
      return;
    }

    /*
    $sql = "select id from civicrm_phone where REGEXP_REPLACE(phone, '[-()+\\s]', '') = '$phone' and contact_id = $contactId";
    $phoneId = CRM_Core_DAO::singleValueQuery($sql);
    if ($phoneId) {
      return;
    }
    */

    \Civi\Api4\Phone::delete(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('location_type_id', '=', 2)
      ->execute();

    \Civi\Api4\Phone::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 2)
      ->addValue('phone', $this->formatPhone($phone))
      ->execute();
  }

  private function formatPhone($phone) {
    $formattedPhone = '+'
      . substr($phone, 0, 2)
      . ' '
      . substr($phone, 2, 1)
      . ' '
      . substr($phone, 3, 3)
      . ' '
      . substr($phone, 6, 2)
      . ' '
      . substr($phone, 8, 2);

    return $formattedPhone;
  }

  private function updateEmail($contactId, $email) {
    $this->debug(__METHOD__);

    $existingEmail = \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('email', '=', $email)
      ->execute()
      ->first();
    if ($existingEmail) {
      return;
    }

    \Civi\Api4\Email::delete(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('location_type_id', '=', 2)
      ->execute();

    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 2)
      ->addValue('email', $email)
      ->addValue('is_primary', 1)
      ->execute();
  }

  private function updateWebsite($contactId, $websiteType, $website) {
    $existingEmail = \Civi\Api4\Website::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('website_type_id:label', '=', $websiteType)
      ->addWhere('url', '=', $website)
      ->execute()
      ->first();
    if ($existingEmail) {
      return;
    }

    \Civi\Api4\Website::delete(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('website_type_id:label', '=', $websiteType)
      ->execute();

    \Civi\Api4\Website::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('website_type_id:label', $websiteType)
      ->addValue('url', $website)
      ->execute();
  }

  private function disableOldCommittees() {
    $this->debug(__METHOD__);

    \Civi\Api4\Relationship::update(FALSE)
      ->addValue('is_active', FALSE)
      ->addValue('end_date', '2024-06-30')
      ->addWhere('end_date', 'IS NULL')
      ->addWhere('start_date', '=', '2024-06-01')
      ->addWhere('relationship_type_id', 'IN', [51, 50, 49, 48])
      ->addWhere('contact_id_b.contact_sub_type', '=', 'EP_Committee')
      ->execute();
  }

  private function updateCommittee($contactId, $committees) {
    $this->debug(__METHOD__);

    if (empty($committees)) {
      return;
    }

    $committeeList = explode('|', $committees);
    foreach ($committeeList as $commitee) {
      echo "  committee = $commitee\n";
      $commiteeId = $this->getCommitteeId($commitee);
      $this->createRelationShip($contactId, $commiteeId, self::REL_TYPE_ID_MEMBER, '2024-07-01');
    }
  }

  private function getCommitteeId($committee) {
    $sql = "
      select
        id
      from
        civicrm_contact
      where
        organization_name like 'Committee%$committee'
      and
        is_deleted = 0
    ";
    $id = CRM_Core_DAO::singleValueQuery($sql);
    if (empty($id)) {
      throw new Exception("Cannot find committee $committee");
    }

    return $id;
  }

  public function validateHeader(): void {
    $this->colIndexes = [
      "first_name" => 0,
      "last_name" => 1,
      "political_group" => 2,
      "phone" => 3,
      "email" => 4,
      "twitter" => 5,
      "committee" => 6,
      "id" => 7,
      "external_reference" => 8,
      "country" => 9,
      "prefix" => 10,
      "mep_gender" => 11,
      "mep_image" => 12,
      "mep_homepage" => 13,
      "mep_uri" => 14,
    ];
  }

  private function debug($m) {
    echo "   $m\n";
  }

  private static function convert( $str ) {
    return iconv( "Windows-1252", "UTF-8", $str );
  }

}
