<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */


namespace api\v4\Entity;

use Civi\Api4\Entity;
use api\v4\UnitTestCase;
use Civi\Api4\Utils\CoreUtil;

/**
 * @group headless
 */
class ConformanceTest extends UnitTestCase {

  use \api\v4\Traits\TableDropperTrait;
  use \api\v4\Traits\OptionCleanupTrait {
    setUp as setUpOptionCleanup;
  }

  /**
   * @var \api\v4\Service\TestCreationParameterProvider
   */
  protected $creationParamProvider;

  /**
   * Set up baseline for testing
   */
  public function setUp(): void {
    $tablesToTruncate = [
      'civicrm_case_type',
      'civicrm_custom_group',
      'civicrm_custom_field',
      'civicrm_group',
      'civicrm_event',
      'civicrm_participant',
    ];
    $this->dropByPrefix('civicrm_value_myfavorite');
    $this->cleanup(['tablesToTruncate' => $tablesToTruncate]);
    $this->setUpOptionCleanup();
    $this->loadDataSet('CaseType');
    $this->loadDataSet('ConformanceTest');
    $this->creationParamProvider = \Civi::container()->get('test.param_provider');
    parent::setUp();
  }

  /**
   * Get entities to test.
   *
   * This is the hi-tech list as generated via Civi's runtime services. It
   * is canonical, but relies on services that may not be available during
   * early parts of PHPUnit lifecycle.
   *
   * @return array
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function getEntitiesHitech() {
    // Ensure all components are enabled so their entities show up
    foreach (array_keys(\CRM_Core_Component::getComponents()) as $component) {
      \CRM_Core_BAO_ConfigSetting::enableComponent($component);
    }
    return $this->toDataProviderArray(Entity::get(FALSE)->execute()->column('name'));
  }

  /**
   * Get entities to test.
   *
   * This is the low-tech list as generated by manual-overrides and direct inspection.
   * It may be summoned at any time during PHPUnit lifecycle, but it may require
   * occasional twiddling to give correct results.
   *
   * @return array
   */
  public function getEntitiesLotech() {
    $manual['add'] = [];
    $manual['remove'] = ['CustomValue'];
    $manual['transform'] = ['CiviCase' => 'Case'];

    $scanned = [];
    $srcDir = dirname(__DIR__, 5);
    foreach ((array) glob("$srcDir/Civi/Api4/*.php") as $name) {
      $fileName = basename($name, '.php');
      $scanned[] = $manual['transform'][$fileName] ?? $fileName;
    }

    $names = array_diff(
      array_unique(array_merge($scanned, $manual['add'])),
      $manual['remove']
    );

    return $this->toDataProviderArray($names);
  }

  /**
   * Ensure that "getEntitiesLotech()" (which is the 'dataProvider') is up to date
   * with "getEntitiesHitech()" (which is a live feed available entities).
   */
  public function testEntitiesProvider() {
    $this->assertEquals($this->getEntitiesHitech(), $this->getEntitiesLotech(), "The lo-tech list of entities does not match the hi-tech list. You probably need to update getEntitiesLotech().");
  }

  /**
   * @param string $entity
   *   Ex: 'Contact'
   *
   * @dataProvider getEntitiesLotech
   *
   * @throws \API_Exception
   */
  public function testConformance($entity): void {
    $entityClass = CoreUtil::getApiClass($entity);

    $this->checkEntityInfo($entityClass);
    $actions = $this->checkActions($entityClass);

    // Go no further if it's not a CRUD entity
    if (array_diff(['get', 'create', 'update', 'delete'], array_keys($actions))) {
      $this->markTestSkipped("The API \"$entity\" does not implement CRUD actions");
    }

    $this->checkFields($entityClass, $entity);
    $id = $this->checkCreation($entity, $entityClass);
    $this->checkGet($entityClass, $id, $entity);
    $this->checkGetCount($entityClass, $id, $entity);
    $this->checkUpdateFailsFromCreate($entityClass, $id);
    $this->checkWrongParamType($entityClass);
    $this->checkDeleteWithNoId($entityClass);
    $this->checkDeletion($entityClass, $id);
    $this->checkPostDelete($entityClass, $id, $entity);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   */
  protected function checkEntityInfo($entityClass): void {
    $info = $entityClass::getInfo();
    $this->assertNotEmpty($info['name']);
    $this->assertNotEmpty($info['title']);
    $this->assertNotEmpty($info['title_plural']);
    $this->assertNotEmpty($info['type']);
    $this->assertNotEmpty($info['description']);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param string $entity
   *
   * @throws \API_Exception
   */
  protected function checkFields($entityClass, $entity) {
    $fields = $entityClass::getFields(FALSE)
      ->setIncludeCustom(FALSE)
      ->execute()
      ->indexBy('name');

    $errMsg = sprintf('%s is missing required ID field', $entity);
    $subset = ['data_type' => 'Integer'];

    $this->assertArrayHasKey('data_type', $fields['id'], $errMsg);
    $this->assertEquals('Integer', $fields['id']['data_type']);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   *
   * @return array
   *
   * @throws \API_Exception
   */
  protected function checkActions($entityClass): array {
    $actions = $entityClass::getActions(FALSE)
      ->execute()
      ->indexBy('name');

    $this->assertNotEmpty($actions);
    return (array) $actions;
  }

  /**
   * @param string $entity
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   *
   * @return mixed
   */
  protected function checkCreation($entity, $entityClass) {
    $requiredParams = $this->creationParamProvider->getRequired($entity);
    $createResult = $entityClass::create()
      ->setValues($requiredParams)
      ->setCheckPermissions(FALSE)
      ->execute()
      ->first();

    $this->assertArrayHasKey('id', $createResult, "create missing ID");
    $id = $createResult['id'];

    $this->assertGreaterThanOrEqual(1, $id, "$entity ID not positive");

    return $id;
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param int $id
   */
  protected function checkUpdateFailsFromCreate($entityClass, $id): void {
    $exceptionThrown = '';
    try {
      $entityClass::create(FALSE)
        ->addValue('id', $id)
        ->execute();
    }
    catch (\API_Exception $e) {
      $exceptionThrown = $e->getMessage();
    }
    $this->assertStringContainsString('id', $exceptionThrown);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param int $id
   * @param string $entity
   */
  protected function checkGet($entityClass, $id, $entity) {
    $getResult = $entityClass::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();

    $errMsg = sprintf('Failed to fetch a %s after creation', $entity);
    $this->assertEquals($id, $getResult->first()['id'], $errMsg);
    $this->assertEquals(1, $getResult->count(), $errMsg);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param int $id
   * @param string $entity
   */
  protected function checkGetCount($entityClass, $id, $entity): void {
    $getResult = $entityClass::get(FALSE)
      ->addWhere('id', '=', $id)
      ->selectRowCount()
      ->execute();
    $errMsg = sprintf('%s getCount failed', $entity);
    $this->assertEquals(1, $getResult->count(), $errMsg);

    $getResult = $entityClass::get(FALSE)
      ->selectRowCount()
      ->execute();
    $errMsg = sprintf('%s getCount failed', $entity);
    $this->assertGreaterThanOrEqual(1, $getResult->count(), $errMsg);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   */
  protected function checkDeleteWithNoId($entityClass) {
    $exceptionThrown = '';
    try {
      $entityClass::delete()
        ->execute();
    }
    catch (\API_Exception $e) {
      $exceptionThrown = $e->getMessage();
    }
    $this->assertStringContainsString('required', $exceptionThrown);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   */
  protected function checkWrongParamType($entityClass) {
    $exceptionThrown = '';
    try {
      $entityClass::get()
        ->setDebug('not a bool')
        ->execute();
    }
    catch (\API_Exception $e) {
      $exceptionThrown = $e->getMessage();
    }
    $this->assertStringContainsString('debug', $exceptionThrown);
    $this->assertStringContainsString('type', $exceptionThrown);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param int $id
   */
  protected function checkDeletion($entityClass, $id) {
    $deleteResult = $entityClass::delete(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();

    // should get back an array of deleted id
    $this->assertEquals([['id' => $id]], (array) $deleteResult);
  }

  /**
   * @param \Civi\Api4\Generic\AbstractEntity|string $entityClass
   * @param int $id
   * @param string $entity
   */
  protected function checkPostDelete($entityClass, $id, $entity) {
    $getDeletedResult = $entityClass::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();

    $errMsg = sprintf('Entity "%s" was not deleted', $entity);
    $this->assertEquals(0, count($getDeletedResult), $errMsg);
  }

  /**
   * @param array $names
   *   List of entity names.
   *   Ex: ['Foo', 'Bar']
   * @return array
   *   List of data-provider arguments, one for each entity-name.
   *   Ex: ['Foo' => ['Foo'], 'Bar' => ['Bar']]
   */
  protected function toDataProviderArray($names) {
    sort($names);

    $result = [];
    foreach ($names as $name) {
      $result[$name] = [$name];
    }
    return $result;
  }

}
